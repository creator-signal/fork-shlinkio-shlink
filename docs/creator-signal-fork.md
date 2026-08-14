# Creator Signal Shlink fork

This fork is the private REST/server half of Creator Signal's link-management stack. Human identity and authorization are deliberately not implemented in Shlink. The paired web client terminates ZITADEL Authorization Code + PKCE, requires `platform:operator`, stores an opaque HTTP-only session, and calls this service over a private route with a dedicated Shlink management key.

## Branches and upstream sync

- `develop` and `main` are exact fast-forward mirrors of `shlinkio/shlink`; never add Creator Signal commits to them.
- `creator-signal/develop` is the default integration and pull-request target.
- `creator-signal/main` is the protected release source.
- Feature branches start at `creator-signal/develop`. Releases promote a tested integration revision to `creator-signal/main` without force-pushes.
- `.github/workflows/creator-signal-sync-upstream.yml` advances the mirror branches only when the update is a fast-forward, then opens a normal sync pull request to `creator-signal/develop`. It does not mirror upstream tags because upstream `v*` workflows must not publish from this fork.

## Management-key provisioning

The existing restricted workload key remains separate:

- `creator-signal-link-creation-api`: restricted authored-short-URL/no-orphan-visits workload credential used by Sales Pulse.
- `creator-signal-web-ui`: unrestricted management credential used only by the OIDC BFF.

Provision the BFF credential from a one-shot container that shares the Shlink database network and a dedicated provider-secret volume:

```sh
shlink api-key:provision-file \
  creator-signal-web-ui \
  /run/secrets/provider/shlink-dashboard-api-key
```

The output path must be absolute and its parent must already exist. A new file is created with mode `0400`; its plaintext value is never printed. A rerun verifies the stored key, name, validity and unrestricted/admin status without rotating it. Symlinks, corrupt files, name conflicts, publication failures and unrecoverable database/file divergence fail closed. Rotation is an explicit operation: disable/delete the named database key, remove the provider file under an audited maintenance procedure, then provision again.

Only the provider initializer and web BFF mount this file. The browser, Shlink service container, Sales Pulse application and backup exports must not receive it. Shlink persists only the SHA-256 key hash.

## REST security contract

- REST authentication is only `X-Api-Key`; query-string API keys are disabled, including on the deprecated single-step endpoint.
- CORS defaults to deny: `CORS_ALLOW_ORIGIN` is empty. The same-origin BFF needs no CORS. An explicit allow-list is a reviewed exception, never `*` in production.
- Access logs record method, URI, status and content length. They do not record headers. Disabling query credentials prevents API keys from entering the logged URI.
- `X-Request-Id` is accepted, propagated to the response and included in Shlink logs. The BFF supplies an opaque request ID, not an identity/access token.
- `/rest/health` stays unauthenticated for dependency-aware monitoring and returns only service status/version information.

## Compatibility and releases

`config/creator-signal-compatibility.json` declares the supported REST major, paired web-client minimum and required operation IDs. `bin/creator-signal-verify-contract.sh` checks those operations against the generated OpenAPI document and rejects query authentication or a changed header scheme.

Creator Signal tags are namespaced (`creator-signal-vX.Y.Z-cs.N`). The release workflow verifies the tag is the exact `creator-signal/main` revision, refuses an already-existing GHCR version, reruns CI, builds/scans amd64 and arm64, publishes `ghcr.io/creator-signal/shlink` with semantic-version, namespaced-version and source-SHA tags, emits an OpenAPI artifact, SBOM, scan report, digest manifest and GitHub OIDC provenance, and only then creates the GitHub release. There is no `latest` tag.

The production Dockerfile pins its PHP, Composer and Go builder image indexes. RoadRunner `v2025.1.15` is rebuilt from its checksummed upstream source archive with Go `1.26.6`, `golang.org/x/text` `v0.39.0` and `google.golang.org/grpc` `v1.82.1`; this keeps the upstream runtime version while removing vulnerabilities present in its prebuilt binary and the Go 1.26.5 standard library. Do not return to `rr get` or lower the Go security floor without a clean container scan.

The lock currently retains `cuyz/valinor` `2.5.0`. Version `2.6.0` changed enum-validation paths so the public error field for `tagsMode` became the internal name `value`, breaking Shlink's API contract and upstream API tests. Remove this compatibility pin only after the mapped error again names the request field and the full API suite proves it on both supported PHP versions.

The deployment tuple is:

```text
ghcr.io/creator-signal/shlink@sha256:<server digest>
+ ghcr.io/creator-signal/shlink-web-client@sha256:<client digest>
+ REST/OpenAPI major 3 and compatibility manifest revision
+ Shlink source revision containing the applied Doctrine migrations
```

Sales Pulse must pin both digests, keep the BFF-to-Shlink route private, add the one-shot provider initializer, and prove clean and preserved-volume migration/backup/restore plus STAGE browser acceptance before production. A fork release is not production deployment authorization.
