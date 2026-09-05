# Licence Log — Upstream Anatomy Repository

**Owner:** Handover 02 (Content lane) · **Opened:** 2026-09-05 · **Status: OPEN — blocking public deployment**

This is the audit trail behind the grant-vs-replace decision. `docs/asset-register.md`
records what each asset *is*; this file records what we did about the rights, when, and
what we decided. It exists so that in Wave 7 nobody has to reconstruct the timeline from
memory.

---

## 1. The finding

`docs/project-context.md` §3 established the problem. Re-verified against the live GitHub
API on **2026-09-05**, so the numbers below are current and not copied forward:

| Evidence | Value |
|---|---|
| Repository | `thebuggeddev/anatomy` |
| `GET /repos/thebuggeddev/anatomy` → `license` | `null` |
| `GET /repos/thebuggeddev/anatomy/license` | HTTP 404 `{"message":"Not Found"}` |
| `LICENSE` / `LICENCE` / `COPYING` / `NOTICE` in the tree | **none** — 167 entries scanned recursively at head `8c0e6f3`, `truncated: false` |
| `license` field in the repo's `package.json` | absent |
| Any sentence matching `/licen/i` in `README.md` | none — the README is unmodified Cloudflare `vinext-starter` boilerplate |
| Attribution / credits / copyright anywhere in the 63 source blobs | none; the only matches were `setAttribute`/`BufferAttribute` |
| Stars / forks / last push | 3,165 / 839 / 2026-08-09 |

**Under default copyright this is all rights reserved.** Public availability of source is
not a grant. Stars and forks are not a grant. Copying the code or the models into a
publicly deployed product would be redistribution of someone else's copyrighted work.

### The models specifically

Every GLB was inspected directly. All nine carry `"generator": "glTF-Transform v4.4.2"`,
`"version": "2.0"`, and **no `copyright` key and no `extras` object anywhere in the JSON
chunk**. Node, mesh and material names are `tripo_node_*` / `tripo_mesh_*` /
`tripo_mat_*`, identifying them as Tripo AI generative output. The 45 `.webp`
illustrations under `public/anatomy/` contain a single `VP8 ` chunk each — no `EXIF`, no
`XMP` — so they carry no embedded creator or copyright metadata either.

Two consequences beyond the legal one:

- Rights in Tripo output depend on the generating account's plan and terms. Whatever
  those rights are, they sit with the repository owner, not with a downstream reader.
- Generative output is of **unverified anatomical accuracy**. That is a content-quality
  risk for a product aimed at 13–18-year-olds, and it is independent of the licence. It
  belongs to F03's owner, who owns content correctness — flagged there, not decided here.

---

## 2. What we are asking for

A written grant covering both halves, because they are separable and a partial grant is
a real possible outcome:

- **(a) Source** — `app/lib/three/{viewer,hotspots,loaders,dispose}.ts` under a
  permissive OSI licence (MIT or Apache-2.0), with the right to modify and redistribute
  as part of a publicly deployed educational application.
- **(b) Models** — the nine GLBs under `public/models/` and the 45 illustrations under
  `public/anatomy/`, with the right to modify (decimate, re-encode, re-texture) and
  redistribute in that same application.

Anything less than both leaves scope open, so the request asks for both explicitly rather
than for "permission to use the project".

### Draft request

Ready to send verbatim. Suggested channel: a public GitHub issue on the repository
(creates a timestamped record both sides can point at), with a follow-up to the email on
the owner's GitHub profile if there is no response inside a week.

> **Subject: Licence clarification request — permission to reuse code and models**
>
> Hello,
>
> I'm building an open educational 3D anatomy learning platform for students aged 13–18,
> and your Anatomy Atelier project is the best starting point I've found for the 3D layer.
> I'd like to reuse parts of it properly rather than assume.
>
> The repository doesn't currently include a LICENSE file, so by default the work is all
> rights reserved and I can't redistribute any of it. Could you clarify what you intend?
>
> Specifically I'm asking for permission to:
>
> 1. Use and adapt the Three.js viewer code (`app/lib/three/*`) — ideally by you adding a
>    permissive licence such as MIT or Apache-2.0 to the repository, which would settle it
>    for everyone rather than just for me.
> 2. Modify and redistribute the nine GLB models in `public/models/` and the illustrations
>    in `public/anatomy/`, as part of a publicly deployed application. If those models were
>    generated with Tripo, it would help to know which plan they were generated under, since
>    that determines what you're able to grant.
>
> I'm happy to credit you and the project prominently in the application's About page and
> in the repository, in whatever form you'd prefer.
>
> If you'd rather not grant either of these, that's completely fine — just say so and I'll
> use openly-licensed anatomy models instead. A clear "no" is genuinely more useful to me
> than no answer, because it lets me plan.
>
> Thank you for building and sharing this.

---

## 3. Correspondence log

Append a row per contact. Never edit a row; add a new one.

| # | Date | Channel | Action | Outcome |
|---|---|---|---|---|
| 1 | 2026-09-05 | — | Licence status re-verified against the GitHub API. `license: null`, no LICENSE file at head `8c0e6f3`. | Confirmed all rights reserved |
| 2 | 2026-09-05 | — | Request drafted (§2 above). **Not sent.** | **Requires a named human to send it** — it is outbound correspondence in a person's own name, and an agent must not send it on their behalf |
| 3 | 2026-09-05 | — | Nine upstream GLBs fetched to a developer machine and run through `scripts/encode-model.mjs` so the viewer has geometry to load locally. Output lands in `public/models/`, which `.gitignore` excludes — **no upstream binary is committed to this repository or deployed**, and `manifest.json` still reads `"status": "pending-licence"`. | Unblocks local development only. The deployment gate in §4 is untouched and still open |
| 4 | | | | |

**Escalation trigger: 2026-09-19** (two weeks from the finding). If no grant has arrived
by then, or a "no" arrives sooner, the replace path becomes the plan of record — see §4.
The fallback is weeks of work and must not be discovered in Wave 5.

---

## 4. Decision record — grant vs replace

### The two paths

| | **Grant** — the owner licenses the work | **Replace** — openly-licensed anatomy |
|---|---|---|
| Unblocks | Code reuse *and* models | Models only; code is reimplemented from technique |
| Model granularity | Single mesh, unchanged (`project-context.md` §2.2) | Per-structure meshes on all three candidates |
| Anatomical accuracy | Unverified generative output | Derived from imaging or dissection data |
| F04's scope | *Adapt* the audited viewer | *Reimplement* the techniques from our own notes |
| F05/F07's scope | Hotspot selection only | Hotspot selection **plus** real mesh raycasting becomes possible |
| Cost if chosen late | — | Weeks. This is why the escalation trigger exists |
| Depends on | A third party choosing to respond | Nobody |

### What the audit already tells us

The grant path, even if it lands in full, does **not** deliver what the PRD describes.
`docs/project-context.md` §2.2 is the central finding: every upstream GLB is one node, one
mesh, one primitive, one material. Per-structure selection, isolation, and layer visibility
are impossible against that geometry no matter who owns it. The grant buys a working demo;
it does not buy the product.

Replacement models resolve the licence question and the single-mesh limitation in one move.
Handover 02 says to treat that as an upside, not a cost, and the evidence supports it.

### Recommendation

**Pursue the grant, plan for replacement.** Send the request (§2) so the answer is on the
record and the code path stays open, but do not schedule any work that assumes a "yes".
Choose the replacement source from `docs/asset-register.md` §5 before the escalation trigger
so that a "no" costs a re-encode, not a re-plan.

### Decision

| Field | Value |
|---|---|
| **Decision** | *not yet taken* |
| **Date** | |
| **Decided by** | |
| **Rationale** | |

Handover 02's Definition of Done requires a **named human** to sign off that the asset set
may be deployed publicly. That signature belongs here and in `docs/asset-register.md` §6.
Until both are filled in, `public/models/manifest.json` stays at
`"status": "pending-licence"` and `node scripts/verify-models.mjs --release` fails by design.

---

## 5. What is already safe to use

Not everything is blocked, and it is worth being precise so the block does not spread
further than it should.

- **Techniques are not copyrightable.** Surface snapping, screen-space sprite picking,
  render-on-demand with a dirty flag, and the depth prepass are described in
  `docs/project-context.md` §2.4. Reimplementing them against Three.js from those notes is
  clean regardless of how the licence question resolves. Budget it as real work.
- **The structural data** — 9 organs, 37 structures, their slugs and Terminologia Anatomica
  terms — is factual anatomical vocabulary, not creative expression. The *authored
  coordinates* are a closer call; F03 re-authors them against whatever models ship anyway,
  since they are only meaningful in that model's pivot space.
- **npm dependencies** are licensed to us directly by their authors, not by this repository.
  See `docs/asset-register.md` §4.
