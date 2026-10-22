# Creator Signal Shlink fork

This fork is the private REST/server half of Creator Signal's link-management stack. Human identity and authorization are deliberately not implemented in Shlink. The paired web client terminates ZITADEL Authorization Code + PKCE, requires `platform:operator`, stores an opaque HTTP-only session, and calls this service over a private route with a dedicated Shlink management key.

## PHP security runtime (Issue #13)

Release `5.2.0-cs.3` retains Shlink 5.2.0 and updates the pinned official runtime
to PHP **8.5.12 CLI on Alpine 3.23**. Its locked phpseclib, Symfony HTTP Foundation
and YAML dependencies receive security patch updates; the Valinor compatibility
pin remains unchanged.
The official image index is
`sha256:307c3a4ece9b0dc24d4e5c1ad6229aab15354144b12f3afed209ed847c855697`;
its Linux amd64 and arm64 configurations both declare PHP 8.5.12. Alpine 3.22
does not provide this official PHP release, so the image moves to Alpine 3.23.
The existing multi-architecture build, database, API and vulnerability gates must
prove the updated runtime and rebuilt extensions before release.

The [PHP security advisory of 21 October 2026](https://www.php.net/archive/2026.php#2026-10-21-5)
requires PHP 8.5.12 to fix CVE-2026-109618 and CVE-2026-109619 on the 8.5 line.
This is the newest stable PHP line; PHP 8.6 release candidates are excluded.
Disabling upload progress alone is incomplete mitigation. The image includes
both the patched engine and `session.upload_progress.enabled=0` before request
parsing. Both architecture builds reject an older engine, enabled upload
progress or missing required PHP extensions, including SQL Server on amd64.
The existing container CI also executes the final runtime as UID 1001.
API tests export only the pinned, source-built RoadRunner binary from the
production Dockerfile's `roadrunner-artifact` target. They no longer rely on
`rr get` finding an older 2025 release in the upstream release-list pagination.

No REST, database schema, key, permission, identity, privacy, routing or storage
contract changes. Retain the prior `5.2.0-cs.2` artifact for rollback; rollback
to its unpatched runtime is not security acceptance. Fork CI/publication is
separate from Sales Pulse digest adoption and authorized environment acceptance.

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

The production Dockerfile pins its PHP, Composer and Go builder image indexes. RoadRunner `v2025.1.15` is rebuilt from its checksummed upstream source archive with Go `1.26.10` and explicit security floors for `github.com/rabbitmq/amqp091-go` `v1.13.0`, `golang.org/x/crypto` `v0.57.0`, `golang.org/x/text` `v0.42.0` and `google.golang.org/grpc` `v1.83.2`, `github.com/klauspost/compress` `v1.18.7` and `golang.org/x/net` `v0.60.0`; this keeps the upstream runtime version while removing vulnerabilities present in its prebuilt binary and older transitive dependencies. Do not return to `rr get` or lower a Go dependency security floor without a clean container scan.

The lock currently retains `cuyz/valinor` `2.5.0`. Version `2.6.0` changed enum-validation paths so the public error field for `tagsMode` became the internal name `value`, breaking Shlink's API contract and upstream API tests. Remove this compatibility pin only after the mapped error again names the request field and the full API suite proves it on both supported PHP versions.

The deployment tuple is:

```text
ghcr.io/creator-signal/shlink@sha256:<server digest>
+ ghcr.io/creator-signal/shlink-web-client@sha256:<client digest>
+ REST/OpenAPI major 3 and compatibility manifest revision
+ Shlink source revision containing the applied Doctrine migrations
```

Sales Pulse must pin both digests, keep the BFF-to-Shlink route private, add the one-shot provider initializer, and prove clean and preserved-volume migration/backup/restore plus STAGE browser acceptance before production. A fork release is not production deployment authorization.
