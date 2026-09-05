/**
 * One configured NodeIO for the whole pipeline, so the encoder and the
 * verifier read files identically. Registering every extension matters here:
 * an unregistered extension is dropped on read, which would make the verifier
 * measure a file that is not the one we shipped.
 */

import { Logger, NodeIO } from '@gltf-transform/core'
import { ALL_EXTENSIONS } from '@gltf-transform/extensions'
import { MeshoptDecoder, MeshoptEncoder } from 'meshoptimizer'

export async function createIO() {
  await MeshoptDecoder.ready
  await MeshoptEncoder.ready

  return (
    new NodeIO()
      // WARN, not the default INFO: the transforms narrate every pass, which
      // drowns out the budget verdict this script exists to print.
      .setLogger(new Logger(Logger.Verbosity.WARN))
      .registerExtensions(ALL_EXTENSIONS)
      .registerDependencies({
        'meshopt.decoder': MeshoptDecoder,
        'meshopt.encoder': MeshoptEncoder,
      })
  )
}
