PHP_ARG_ENABLE([kumwe_engine], [whether to enable the Kumwe Engine binding],
  [AS_HELP_STRING([--enable-kumwe_engine], [Enable the self-contained Kumwe Engine extension])], [yes])
if test "$PHP_KUMWE_ENGINE" != "no"; then
  AC_REQUIRE([AC_CANONICAL_HOST])
  case "$host_os:$host_cpu" in
    linux*:x86_64) ;;
    *) AC_MSG_ERROR([This candidate supports Linux x86_64 only]) ;;
  esac
  PHP_REQUIRE_CXX()
  AC_PATH_PROG([CMAKE], [cmake], [no])
  AS_IF([test "$CMAKE" = "no"], [AC_MSG_ERROR([CMake 3.25 or newer is required])])
  KUMWE_PHP_EXECUTABLE=`$PHP_CONFIG --php-binary`
  "$KUMWE_PHP_EXECUTABLE" "$srcdir/tools/verify-engine.php" || AC_MSG_ERROR([Embedded Engine verification failed])
  $CMAKE -S "$srcdir/vendor/engine" -B "$ac_pwd/engine-build" -DBUILD_TESTING=OFF -DCMAKE_BUILD_TYPE=Release || AC_MSG_ERROR([Embedded Engine configuration failed])
  $CMAKE --build "$ac_pwd/engine-build" --target kumwe_engine --parallel 2 || AC_MSG_ERROR([Embedded Engine compilation failed])
  PHP_NEW_EXTENSION([kumwe_engine], [src/kumwe_engine.c], [$ext_shared],, [-std=c11 -Wall -Wextra -fstack-protector-strong])
  PHP_ADD_INCLUDE([$ext_srcdir])
  PHP_ADD_INCLUDE([$ext_srcdir/src])
  PHP_ADD_INCLUDE([$ext_srcdir/vendor/engine/include])
  PHP_ADD_LIBRARY_WITH_PATH([kumwe_engine], [$ac_pwd/engine-build], [KUMWE_ENGINE_SHARED_LIBADD])
  PHP_ADD_LIBRARY([stdc++], [1], [KUMWE_ENGINE_SHARED_LIBADD])
  PHP_SUBST([KUMWE_ENGINE_SHARED_LIBADD])
  PHP_ADD_EXTENSION_DEP(kumwe_engine, json)
  PHP_ADD_EXTENSION_DEP(kumwe_engine, random)
  PHP_ADD_EXTENSION_DEP(kumwe_engine, spl)
fi
