Trivial known-good two-file app. Baseline sanity check: if this ever fails
build or smoke mode, the pipeline itself is broken (bad esbuild flags,
missing canonical module, broken runtime install) -- not the fixture.

build mode: should succeed.
smoke mode: bodyText should contain "hello fixture". console_errors will
contain one "Failed to load resource: 404" line (the platform's auth check
against the fake preview project ID, expected on every smoke_test run per
ai_smoke_test_files()'s own doc comment) -- that's normal noise, not a
failure. What matters is the ABSENCE of anything else: no "Element type is
invalid", no other React warnings/errors.
