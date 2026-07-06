import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const formPost = vi.fn()
const formDelete = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1, name: 'Tester' } }, flash: { extra: '[]' } } }),
    router: { post: vi.fn() },
    useForm: (data) => ({
        ...data,
        post: formPost,
        delete: formDelete,
        reset: vi.fn(),
    }),
}))

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve()),
        post: vi.fn(() => Promise.resolve({ data: [{ id: 7, name: 'x', path: 'p' }] })),
        delete: vi.fn(() => Promise.resolve({ data: { deleted: true } })),
    },
}))

vi.mock('@/Utilities/Utilities.js', () => ({ hasPermission: () => true }))
vi.mock('glightbox', () => ({ default: () => ({ reload: vi.fn(), on: vi.fn() }) }))
vi.mock('tui-image-editor', () => ({ default: class {} }))

import axios from 'axios'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'

const baseProps = {
    imageableId: 5,
    imageableType: 'App\\Models\\Event',
    existing: [{ id: 1, name: 'foto', path: 'uploaded/x.jpg', pivot: { main: false } }],
}

describe('ImageUploadComponent', () => {
    beforeEach(() => {
        formPost.mockClear()
        formDelete.mockClear()
        axios.post.mockClear()
        axios.delete.mockClear()
    })

    it('deletes via Inertia form.delete when apiMode is not set', async () => {
        const wrapper = mount(ImageUploadComponent, { props: baseProps })
        await wrapper.vm.deleteImage(1)

        expect(formDelete).toHaveBeenCalledWith('/images/1', expect.anything())
        expect(axios.delete).not.toHaveBeenCalled()
    })

    it('deletes via axios and emits imageDeleted when apiMode is true', async () => {
        const wrapper = mount(ImageUploadComponent, { props: { ...baseProps, apiMode: true } })
        await wrapper.vm.deleteImage(1)
        await Promise.resolve()

        expect(axios.delete).toHaveBeenCalledWith('/api/images/1', expect.anything())
        expect(formDelete).not.toHaveBeenCalled()
        expect(wrapper.emitted('imageDeleted')).toBeTruthy()
    })
})
