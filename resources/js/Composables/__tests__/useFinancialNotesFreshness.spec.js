import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, nextTick } from 'vue'
import { mount } from '@vue/test-utils'

vi.mock('axios', () => ({
    default: { get: vi.fn() },
}))

import axios from 'axios'
import { useFinancialNotesFreshness } from '@/Composables/useFinancialNotesFreshness'

function withComposable(args) {
    let api
    const wrapper = mount({
        setup() {
            api = useFinancialNotesFreshness(...args)
            return () => null
        },
    })

    return { api, wrapper }
}

function respondWith(saved_at, saved_by = null) {
    axios.get.mockResolvedValue({ data: { saved_at, saved_by } })
}

describe('useFinancialNotesFreshness', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        axios.get.mockReset()
    })

    it('is not stale when the server matches the baseline it started from', async () => {
        respondWith('2026-07-20T10:00:00+00:00')
        const { api, wrapper } = withComposable([7, { initialSavedAt: '2026-07-20T10:00:00+00:00' }])

        await api.check()
        await nextTick()

        expect(api.isStale.value).toBe(false)
        wrapper.unmount()
    })

    it('becomes stale when someone else saves a newer version', async () => {
        respondWith('2026-07-20T10:05:00+00:00', { id: 2, name: 'Piet Jansen' })
        const { api, wrapper } = withComposable([7, { initialSavedAt: '2026-07-20T10:00:00+00:00' }])

        await api.check()
        await nextTick()

        expect(api.isStale.value).toBe(true)
        expect(api.staleByName.value).toBe('Piet Jansen')
        wrapper.unmount()
    })

    it('does not report our own save as a foreign change', async () => {
        respondWith('2026-07-20T10:05:00+00:00', { id: 1, name: 'Ik' })
        const { api, wrapper } = withComposable([7, { initialSavedAt: '2026-07-20T10:00:00+00:00' }])

        api.markSaved('2026-07-20T10:05:00+00:00')
        await api.check()
        await nextTick()

        expect(api.isStale.value).toBe(false)
        wrapper.unmount()
    })

    it('is not stale when nothing has ever been saved', async () => {
        respondWith(null)
        const { api, wrapper } = withComposable([7, { initialSavedAt: null }])

        await api.check()
        await nextTick()

        expect(api.isStale.value).toBe(false)
        wrapper.unmount()
    })

    it('becomes stale when a first save appears from elsewhere', async () => {
        respondWith('2026-07-20T10:05:00+00:00', { id: 2, name: 'Piet Jansen' })
        const { api, wrapper } = withComposable([7, { initialSavedAt: null }])

        await api.check()
        await nextTick()

        expect(api.isStale.value).toBe(true)
        wrapper.unmount()
    })

    it('skips polling while a save of our own is in flight', async () => {
        respondWith('2026-07-20T10:05:00+00:00')
        const isPaused = ref(true)
        const { api, wrapper } = withComposable([7, { initialSavedAt: '2026-07-20T10:00:00+00:00', isPaused }])

        await api.check()
        expect(axios.get).not.toHaveBeenCalled()

        isPaused.value = false
        await api.check()
        expect(axios.get).toHaveBeenCalledOnce()

        wrapper.unmount()
    })

    it('polls on the configured interval and stops once unmounted', async () => {
        respondWith('2026-07-20T10:00:00+00:00')
        const { wrapper } = withComposable([7, { initialSavedAt: '2026-07-20T10:00:00+00:00', intervalMs: 1000 }])

        await vi.advanceTimersByTimeAsync(3000)
        expect(axios.get).toHaveBeenCalledTimes(3)

        wrapper.unmount()
        await vi.advanceTimersByTimeAsync(5000)
        expect(axios.get).toHaveBeenCalledTimes(3)
    })

    it('never polls when disabled, so users without the permission cause no 403s', async () => {
        respondWith('2026-07-20T10:05:00+00:00')
        const { api, wrapper } = withComposable([7, {
            initialSavedAt: null,
            intervalMs: 1000,
            enabled: false,
        }])

        await vi.advanceTimersByTimeAsync(5000)
        await api.check()

        expect(axios.get).not.toHaveBeenCalled()
        expect(api.isStale.value).toBe(false)

        wrapper.unmount()
    })

    it('survives a failed poll without disrupting editing', async () => {
        axios.get.mockRejectedValue(new Error('network down'))
        const { api, wrapper } = withComposable([7, { initialSavedAt: '2026-07-20T10:00:00+00:00' }])

        await expect(api.check()).resolves.toBeUndefined()
        expect(api.isStale.value).toBe(false)

        wrapper.unmount()
    })
})
