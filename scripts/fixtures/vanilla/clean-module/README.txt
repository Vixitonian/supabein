A correctly-written module: references itself by its own top-level const
name (never `this`), wires its only interactive element via
addEventListener (never inline onX=""). Baseline false-positive check --
validate mode should report 0 findings. If this one ever starts producing
findings, the regexes themselves broke (too broad), not the fixture.
