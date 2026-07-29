A feature module using `this` inside a shorthand method (job 221's shape --
router.onHashChange() invokes handlers as a bare call, so `this` is undefined
at call time). validate mode should report exactly 1 finding, category
"script", mentioning the word "this".
