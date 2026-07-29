A feature module building a list view via a template string with an inline
onclick="" attribute (job 220's shape, generalized -- catches this
regardless of whether the onclick references a const/let-declared
identifier by name). validate mode should report exactly 1 finding,
category "script", mentioning "onclick".
