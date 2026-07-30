A correctly-written module: references itself by its own top-level const
name (never `this`), wires its only interactive element via
addEventListener (never inline onX=""). index.html includes a real
external CDN <script src> (Tailwind) alongside the local feature script --
this regression-tests the dangling-<script src> check, which used to
false-positive on any external URL (caught live via job 220's stored
plan, fixed same pass as wiring this validator into the autofix loop).
Baseline false-positive check -- validate mode should report 0 findings.
If this one ever starts producing findings, the regexes themselves broke
(too broad), not the fixture.
