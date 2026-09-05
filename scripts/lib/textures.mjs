/**
 * Texture encoding.
 *
 * docs/architecture.md §15.1 asks for KTX2/Basis at 1024² or 2048². That needs
 * the Khronos KTX-Software `ktx` binary, which is not an npm package — so the
 * pipeline detects it and says so rather than silently producing something
 * other than what was asked for. WebP is the documented fallback: it is what
 * six of the nine audited models already use and it fixes the three JPEG
 * outliers on payload, but it buys no GPU-side compression.
 */

import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { KHRTextureBasisu } from '@gltf-transform/extensions'
import { getTextureColorSpace, listTextureSlots, textureCompress } from '@gltf-transform/functions'
import sharp from 'sharp'

export const KTX_INSTALL_HINT =
  'Install Khronos KTX-Software (https://github.com/KhronosGroup/KTX-Software/releases) so ' +
  '`ktx` is on PATH, or re-run with --textures=webp.'

/** Whether the `ktx` CLI is callable, and at what version. */
export function detectKtx() {
  try {
    const output = execFileSync('ktx', ['--version'], { encoding: 'utf8', stdio: 'pipe' })

    return { available: true, version: output.trim().split('\n')[0] }
  } catch {
    return { available: false, version: null }
  }
}

/** Resize to the target square and re-encode as WebP. */
export async function compressToWebp(document, { size, quality }) {
  await document.transform(
    textureCompress({
      encoder: sharp,
      targetFormat: 'webp',
      resize: [size, size],
      quality,
    }),
  )

  return { codec: 'image/webp' }
}

/**
 * ETC1S for colour, UASTC for everything else.
 *
 * ETC1S is far smaller but it is a two-endpoint format — it mangles normal
 * maps and packed ORM channels. Splitting on colour space is the standard
 * recipe and the reason this is not one flag.
 */
export async function compressToKtx2(document, { size, uastcQuality, zstdLevel }) {
  const ktx = detectKtx()

  if (!ktx.available) {
    throw new Error(`KTX2 requested but the \`ktx\` CLI was not found. ${KTX_INSTALL_HINT}`)
  }

  const workDir = mkdtempSync(join(tmpdir(), 'anatolab-ktx-'))
  const encoded = []

  try {
    for (const texture of document.getRoot().listTextures()) {
      const image = texture.getImage()
      if (!image) continue

      const isColour = getTextureColorSpace(texture) === 'srgb'
      const slots = listTextureSlots(texture)
      const name = texture.getName() || `texture-${encoded.length}`

      const pngPath = join(workDir, `${encoded.length}.png`)
      const ktx2Path = join(workDir, `${encoded.length}.ktx2`)

      // ktx create reads PNG, not WebP, so everything goes through one decode.
      const png = await sharp(Buffer.from(image))
        .resize(size, size, { fit: 'fill' })
        .png()
        .toBuffer()

      writeFileSync(pngPath, png)

      const args = [
        'create',
        '--format',
        isColour ? 'R8G8B8A8_SRGB' : 'R8G8B8A8_UNORM',
        '--encode',
        isColour ? 'basis-lz' : 'uastc',
        '--generate-mipmap',
        ...(isColour ? [] : ['--uastc-quality', String(uastcQuality), '--zstd', String(zstdLevel)]),
        '--assign-oetf',
        isColour ? 'srgb' : 'linear',
        pngPath,
        ktx2Path,
      ]

      execFileSync('ktx', args, { stdio: 'pipe' })

      texture.setImage(new Uint8Array(readFileSync(ktx2Path))).setMimeType('image/ktx2')

      encoded.push({ name, slots, mode: isColour ? 'basis-lz' : 'uastc' })
    }

    if (encoded.length > 0) {
      document.createExtension(KHRTextureBasisu).setRequired(true)
    }

    return { codec: 'image/ktx2', ktxVersion: ktx.version, encoded }
  } finally {
    rmSync(workDir, { recursive: true, force: true })
  }
}
