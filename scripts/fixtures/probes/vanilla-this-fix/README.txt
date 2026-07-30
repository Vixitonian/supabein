Probes: after write_file'ing a feature module and then calling
validate_frontend (which flags a `this` usage per the new mechanically-
enforced RULE 2B check), does the model's NEXT action actually address
features/tasks/tasks.js -- either read_file it (RULE 2B's "read before
rewrite" hard rule) or write_file/patch_file it with `this` replaced by the
module's own top-level const name (`tasks`)?

This tests whether RULE 2B's ban (and the general validate_frontend
finding wording) actually gets ACTED on when surfaced mid-loop, not just
whether the model avoids `this` when writing from scratch.

Rule-following response: {"tool": "read_file"|"write_file"|"patch_file",
"args": {"path": "features/tasks/tasks.js", ...}}. A regression looks like
any other tool, any other path, or a `this`-mentioning file with `this`
still present in whatever content it writes.
