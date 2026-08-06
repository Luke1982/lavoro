import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'

import ChaptersComponent from '../ChaptersComponent.vue'
import ChapterHeader from '../ChapterHeader.vue'
import ChapterHeaders from '../ChapterHeaders.vue'
import ChapterContents from '../ChapterContents.vue'

/**
 * Chaptering with and without an owner.
 *
 * A show page just wants tabs and does not care which one is open. A widget that
 * jumps to a chapter from somewhere else — a "bekijk alles" link, a footer —
 * has to be able to say so, so the index can be handed over with v-model. Both
 * roads have to keep working: four pages are on the first one.
 */
const chapters = (props = {}) => mount({
    components: { ChaptersComponent, ChapterHeaders, ChapterHeader, ChapterContents },
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: `
        <ChaptersComponent :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)">
            <ChapterHeaders>
                <ChapterHeader :index="0">Eerste</ChapterHeader>
                <ChapterHeader :index="1">Tweede</ChapterHeader>
            </ChapterHeaders>
            <ChapterContents>
                <template #chapter-0><p>inhoud een</p></template>
                <template #chapter-1><p>inhoud twee</p></template>
            </ChapterContents>
        </ChaptersComponent>
    `,
}, { props, global: { stubs: { SelectMenuComponent: { template: '<div />' } } } })

const clickTab = async (wrapper, label) => {
    await wrapper.findAll('button').find((button) => button.text() === label).trigger('click')
    await wrapper.vm.$nextTick()
}

describe('chaptering', () => {
    it('keeps its own chapter when nobody claims it', async () => {
        const wrapper = chapters()

        expect(wrapper.text()).toContain('inhoud een')

        await clickTab(wrapper, 'Tweede')

        expect(wrapper.text()).toContain('inhoud twee')
    })

    it('follows the chapter it is given', async () => {
        const wrapper = chapters({ modelValue: 0 })

        expect(wrapper.text()).toContain('inhoud een')

        await wrapper.setProps({ modelValue: 1 })

        expect(wrapper.text()).toContain('inhoud twee')
    })

    /** A tab click has to reach the owner, or the two disagree about what is open. */
    it('reports a tab click to whoever owns the chapter', async () => {
        const wrapper = chapters({ modelValue: 0 })

        await clickTab(wrapper, 'Tweede')

        expect(wrapper.emitted('update:modelValue')).toEqual([[1]])
    })

    it('sizes its tabs down when the frame is narrow', () => {
        const roomy = mount(ChaptersComponent, {
            slots: { default: h(ChapterHeader, { index: 0 }, () => 'Eerste') },
        })
        const narrow = mount(ChaptersComponent, {
            props: { dense: true },
            slots: { default: h(ChapterHeader, { index: 0 }, () => 'Eerste') },
        })

        expect(roomy.find('button').classes()).toContain('px-8')
        expect(narrow.find('button').classes()).toContain('px-3')
    })
})
