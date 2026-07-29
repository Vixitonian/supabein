Runtime-only "component resolves to undefined" bug (a typo'd lookup, not an
import/export mismatch -- esbuild already catches that class statically at
build time, confirmed separately). This is the failure shape job 226 actually
hit (React error #130).

build mode: should succeed (esbuild can't see this bug, only a browser can).
smoke mode: console_errors should include "Element type is invalid... Check
the render method of `App`." -- the dev-mode error-decoding regression test.
Before the dev-mode fix this only ever showed as the opaque
"Minified React error #130" with no file/component name.
