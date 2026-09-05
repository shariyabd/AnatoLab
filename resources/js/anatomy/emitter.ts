/**
 * Typed event emitter for the viewer.
 *
 * Replaces the audited implementation's constructor callback bag
 * (`{ onSelect, onPick, … }`), which could not express "two listeners" or
 * "stop listening" and forced every new event through a constructor change
 * (docs/architecture.md §5.2).
 *
 * Keyed on `ViewerEventMap` so the handler's parameter is inferred from the
 * event name at the call site — `on('organ:loaded', e => e.triangles)` type
 * checks, `e.structure` does not.
 */

import type { Unsubscribe, ViewerEventMap, ViewerEventName } from './types'

type Handler<E extends ViewerEventName> = (payload: ViewerEventMap[E]) => void

export class TypedEmitter {
  /**
   * A Set per event so the same handler cannot be registered twice and
   * unsubscribing is O(1). Values are widened to `Handler<ViewerEventName>`
   * because a heterogeneous map cannot be expressed without one cast; the
   * cast is contained here and both public methods stay exact.
   */
  readonly #handlers = new Map<ViewerEventName, Set<Handler<never>>>()

  on<E extends ViewerEventName>(event: E, handler: Handler<E>): Unsubscribe {
    let set = this.#handlers.get(event)
    if (set === undefined) {
      set = new Set()
      this.#handlers.set(event, set)
    }
    set.add(handler as Handler<never>)

    let attached = true
    return () => {
      // Idempotent: a Vue component that unsubscribes in both onUnmounted and
      // a watcher teardown must not corrupt a later subscription.
      if (!attached) return
      attached = false
      this.#handlers.get(event)?.delete(handler as Handler<never>)
    }
  }

  emit<E extends ViewerEventName>(event: E, payload: ViewerEventMap[E]): void {
    const set = this.#handlers.get(event)
    if (set === undefined) return
    // Copy before iterating: a handler is allowed to unsubscribe itself, and a
    // one-shot "wait for organ:loaded" listener does exactly that.
    for (const handler of [...set]) {
      ;(handler as Handler<E>)(payload)
    }
  }

  /** How many handlers are attached; used by the disposal tests. */
  countFor(event: ViewerEventName): number {
    return this.#handlers.get(event)?.size ?? 0
  }

  clear(): void {
    this.#handlers.clear()
  }
}
