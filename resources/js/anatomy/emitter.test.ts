import { describe, expect, it, vi } from 'vitest'
import { TypedEmitter } from './emitter'

describe('TypedEmitter', () => {
  it('delivers a payload to every handler for the event', () => {
    const emitter = new TypedEmitter()
    const first = vi.fn()
    const second = vi.fn()

    emitter.on('load:progress', first)
    emitter.on('load:progress', second)
    emitter.emit('load:progress', { loaded: 10, total: 20 })

    expect(first).toHaveBeenCalledWith({ loaded: 10, total: 20 })
    expect(second).toHaveBeenCalledWith({ loaded: 10, total: 20 })
  })

  it('does not deliver across event names', () => {
    const emitter = new TypedEmitter()
    const handler = vi.fn()

    emitter.on('organ:loaded', handler)
    emitter.emit('load:progress', { loaded: 1, total: 2 })

    expect(handler).not.toHaveBeenCalled()
  })

  it('stops delivering after the returned unsubscribe is called', () => {
    const emitter = new TypedEmitter()
    const handler = vi.fn()

    const unsubscribe = emitter.on('load:progress', handler)
    unsubscribe()
    emitter.emit('load:progress', { loaded: 1, total: 2 })

    expect(handler).not.toHaveBeenCalled()
    expect(emitter.countFor('load:progress')).toBe(0)
  })

  it('survives a handler that unsubscribes itself mid-emit', () => {
    // The "wait for the next organ:loaded" pattern the composable uses; iterating
    // the live Set would skip the following handler.
    const emitter = new TypedEmitter()
    const order: string[] = []

    const unsubscribe = emitter.on('load:progress', () => {
      order.push('first')
      unsubscribe()
    })
    emitter.on('load:progress', () => order.push('second'))

    emitter.emit('load:progress', { loaded: 1, total: 2 })

    expect(order).toEqual(['first', 'second'])
    expect(emitter.countFor('load:progress')).toBe(1)
  })

  it('treats a second unsubscribe as a no-op', () => {
    const emitter = new TypedEmitter()
    const handler = vi.fn()

    const unsubscribe = emitter.on('load:progress', handler)
    unsubscribe()
    emitter.on('load:progress', handler)
    unsubscribe()

    emitter.emit('load:progress', { loaded: 1, total: 2 })
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('drops every handler on clear', () => {
    const emitter = new TypedEmitter()
    emitter.on('load:progress', vi.fn())
    emitter.clear()
    expect(emitter.countFor('load:progress')).toBe(0)
  })
})
