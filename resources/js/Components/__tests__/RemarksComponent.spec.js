import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const postMock = vi.fn()
const deleteMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1, name: 'Tester' } } } }),
    useForm: (data) => ({
        ...data,
        post: postMock,
        delete: deleteMock,
        reset: vi.fn(),
    }),
}))

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve()),
        post: vi.fn(() => Promise.resolve({ data: { id: 99, content: 'hi', user: { id: 1, name: 'Tester' } } })),
        delete: vi.fn(() => Promise.resolve({ data: { deleted: true } })),
    },
}))

import axios from 'axios'
import RemarksComponent from '@/Components/RemarksComponent.vue'

const baseProps = {
    comments: [],
    remarkableType: 'App\\Models\\Event',
    remarkableId: 5,
}

describe('RemarksComponent', () => {
    beforeEach(() => {
        postMock.mockClear()
        deleteMock.mockClear()
        axios.post.mockClear()
    })

    it('uses Inertia form.post when apiMode is not set', async () => {
        const wrapper = mount(RemarksComponent, { props: baseProps })
        await wrapper.find('textarea').setValue('Een opmerking')
        await wrapper.find('button').trigger('click')

        expect(postMock).toHaveBeenCalledWith('/remarks', expect.anything())
        expect(axios.post).not.toHaveBeenCalled()
        expect(wrapper.emitted('created')).toBeFalsy()
    })

    it('uses axios and emits created when apiMode is true', async () => {
        const wrapper = mount(RemarksComponent, { props: { ...baseProps, apiMode: true } })
        await wrapper.find('textarea').setValue('Een opmerking')
        await wrapper.find('button').trigger('click')
        await Promise.resolve()
        await Promise.resolve()

        expect(axios.post).toHaveBeenCalledWith('/api/remarks', expect.objectContaining({
            content: 'Een opmerking',
            remarkable_type: 'App\\Models\\Event',
            remarkable_id: 5,
        }))
        expect(postMock).not.toHaveBeenCalled()
        expect(wrapper.emitted('created')).toBeTruthy()
    })
})
