;; Development tools missing from the host, loaded by direnv (.envrc,
;; `use guix_manifest` from personal-os).  Git, uv, Podman (and its `docker`
;; shim, which the container tests call), hadolint and betterleaks come from
;; the host; the worker's Python and its packages come from uv (worker/uv.lock),
;; and the hook runner from pyproject.toml.  By hand:
;;   guix shell -m manifest.scm
(specifications->manifest
 '("make"
   "php@8.5"       ; the extension's checks (make test/php); CI uses 8.5 with
                   ; mbstring, intl and pdo_sqlite, which Guix's php includes
   "shellcheck"    ; prek hooks for the test and CI scripts
   "shfmt"))
