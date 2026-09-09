// Explicit diagnostic permission restoration. Never linked into the extension.
// PHP supplies already verified bytes; no fixture executable is ever invoked.
#include <algorithm>
#include <array>
#include <cerrno>
#include <cstdint>
#include <cstring>
#include <fcntl.h>
#include <iostream>
#include <limits>
#include <set>
#include <sstream>
#include <stdexcept>
#include <string>
#include <sys/stat.h>
#include <unistd.h>
#include <utility>
#include <vector>

namespace {
struct Descriptor {
    int value;
    explicit Descriptor(int fd) : value(fd) {
        if (fd < 0) throw std::runtime_error("Cannot open a no-follow fixture descriptor.");
    }
    Descriptor(const Descriptor&) = delete;
    Descriptor& operator=(const Descriptor&) = delete;
    Descriptor(Descriptor&& other) noexcept : value(std::exchange(other.value, -1)) {}
    Descriptor& operator=(Descriptor&& other) noexcept {
        if (value >= 0) close(value);
        value = std::exchange(other.value, -1);
        return *this;
    }
    ~Descriptor() { if (value >= 0) close(value); }
};

std::uint64_t number(const std::string& text) {
    if (text.empty() || text.find_first_not_of("0123456789") != std::string::npos)
        throw std::runtime_error("Invalid activation metadata.");
    std::size_t consumed = 0;
    const auto value = std::stoull(text, &consumed);
    if (consumed != text.size()) throw std::runtime_error("Invalid activation integer.");
    return value;
}

struct stat inspect(int fd) {
    struct stat result {};
    if (fstat(fd, &result) != 0 || !S_ISREG(result.st_mode) || result.st_nlink != 1)
        throw std::runtime_error("Expected an unlinked regular activation file.");
    return result;
}

bool same(const struct stat& a, const struct stat& b) {
    return a.st_dev == b.st_dev && a.st_ino == b.st_ino && a.st_size == b.st_size
        && a.st_mtim.tv_sec == b.st_mtim.tv_sec && a.st_mtim.tv_nsec == b.st_mtim.tv_nsec
        && a.st_ctim.tv_sec == b.st_ctim.tv_sec && a.st_ctim.tv_nsec == b.st_ctim.tv_nsec;
}

Descriptor open_file(int root, const std::string& name) {
    if (name.empty() || name.size() > 240 || name.front() == '/'
        || name.find_first_not_of("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._+/-")
            != std::string::npos)
        throw std::runtime_error("Unsafe activation path.");
    Descriptor parent(dup(root));
    std::size_t start = 0;
    while (true) {
        const auto end = name.find('/', start);
        const auto part = name.substr(start, end == std::string::npos ? end : end - start);
        if (part.empty() || part == "." || part == "..") throw std::runtime_error("Unsafe activation component.");
        if (end == std::string::npos)
            return Descriptor(openat(parent.value, part.c_str(), O_RDONLY | O_NOFOLLOW | O_NONBLOCK | O_CLOEXEC));
        parent = Descriptor(openat(parent.value, part.c_str(), O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC));
        start = end + 1;
    }
}

void compare_bytes(int fd, std::uint64_t count) {
    std::array<char, 65536> expected {};
    std::array<char, 65536> actual {};
    while (count != 0) {
        const auto length = static_cast<std::size_t>(std::min<std::uint64_t>(count, expected.size()));
        std::cin.read(expected.data(), static_cast<std::streamsize>(length));
        if (std::cin.gcount() != static_cast<std::streamsize>(length))
            throw std::runtime_error("Activation input ended before authorization.");
        std::size_t offset = 0;
        while (offset < length) {
            const auto got = read(fd, actual.data() + offset, length - offset);
            if (got < 0 && errno == EINTR) continue;
            if (got <= 0) throw std::runtime_error("Fixture changed during activation.");
            offset += static_cast<std::size_t>(got);
        }
        if (std::memcmp(expected.data(), actual.data(), length) != 0)
            throw std::runtime_error("Fixture byte identity changed before activation.");
        count -= length;
    }
}

struct Entry {
    Descriptor file;
    struct stat before;
    mode_t mode;
};
} // namespace

int main(int argc, char** argv) {
    try {
        if (argc != 2) throw std::runtime_error("Expected the verified fixture root.");
        Descriptor root(open(argv[1], O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC));
        std::vector<Entry> entries;
        std::set<std::string> paths;
        std::uint64_t total = 0;
        bool committed = false;
        std::string line;
        while (std::getline(std::cin, line)) {
            if (line == "COMMIT") {
                committed = true;
                break;
            }
            if (line.size() > 512 || entries.size() >= 1024)
                throw std::runtime_error("Activation closure exceeds its bound.");
            std::vector<std::string> fields;
            std::istringstream stream(line);
            std::string field;
            while (std::getline(stream, field, '\t')) fields.push_back(field);
            if (fields.size() != 5 || !paths.insert(fields[0]).second)
                throw std::runtime_error("Invalid or repeated activation entry.");
            const auto size = number(fields[1]);
            const auto mode = number(fields[2]);
            const auto device = number(fields[3]);
            const auto inode = number(fields[4]);
            if (size > 512ULL * 1024 * 1024 || total > 512ULL * 1024 * 1024 - size
                || (mode != 0644 && mode != 0755))
                throw std::runtime_error("Invalid activation size or mode.");
            total += size;
            auto file = open_file(root.value, fields[0]);
            const auto before = inspect(file.value);
            if (before.st_size < 0 || static_cast<std::uint64_t>(before.st_size) != size
                || static_cast<std::uint64_t>(before.st_dev) != device
                || static_cast<std::uint64_t>(before.st_ino) != inode)
                throw std::runtime_error("Fixture identity changed before activation.");
            compare_bytes(file.value, size);
            if (!same(before, inspect(file.value)))
                throw std::runtime_error("Fixture changed during activation verification.");
            entries.push_back({std::move(file), before, static_cast<mode_t>(mode)});
        }
        if (!committed || entries.empty() || std::cin.peek() != std::char_traits<char>::eof())
            throw std::runtime_error("Activation was not completely verified and authorized.");
        for (const auto& entry : entries) {
            if (!same(entry.before, inspect(entry.file.value)))
                throw std::runtime_error("Fixture changed before permission restoration.");
        }
        for (const auto& entry : entries) {
            if (fchmod(entry.file.value, entry.mode) != 0)
                throw std::runtime_error("Cannot restore verified fixture permissions.");
        }
        return 0;
    } catch (const std::exception& error) {
        std::cerr << error.what() << '\n';
        return 1;
    }
}
