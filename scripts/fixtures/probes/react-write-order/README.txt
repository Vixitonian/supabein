Probes: right after plan is accepted, does the model's FIRST write_file
target a leaf/feature component (TaskList.jsx or TaskForm.jsx) instead of
App.jsx? This is what fix #207 (bottom-up write order in RULE 2B/the closing
paragraph of AI_BUILD_FRONTEND_AGENT_SYSTEM_HEADER_REACT) is meant to
produce -- App.jsx imports both feature files, so writing it first hits the
esbuild "Could not resolve" failure that jobs 224-226 repeatedly hit live.

Rule-following response: {"tool":"write_file", "args":{"path":
"features/tasks/TaskList.jsx", ...}} or "features/tasks/TaskForm.jsx" --
anything EXCEPT "App.jsx". A regression (the prompt guidance stopped
landing) looks like path == "App.jsx".
