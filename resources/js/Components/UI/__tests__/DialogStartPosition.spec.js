import { afterEach, beforeAll, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, onMounted } from 'vue'
import DrawerComponent from '../DrawerComponent.vue'
import ModalDialog from '../ModalDialog.vue'

/**
 * Content that measures itself while it mounts — auto-animate, the signature
 * pad — makes the browser compute the dialog's style right then. Whatever
 * position the panel has at that moment is where its animation starts, so it
 * has to be the starting position already; otherwise the start is the end and
 * the dialog pops open. Headless UI only adds its enter-from classes after the
 * content has mounted, so the dialogs carry them from the start.
 */
let seen

const Measuring = defineComponent({
    setup() {
        onMounted(() => {
            const panel = document.querySelector('[id^="headlessui-dialog-panel"]')
            const backdrop = document.querySelector('[data-dialog-backdrop]')
            seen = { panel: [...panel.classList], backdrop: [...backdrop.classList] }
        })

        return () => h('p', 'inhoud')
    },
})

const dialogs = [
    { name: 'DrawerComponent', component: DrawerComponent, prop: 'modelValue', start: ['translate-x-full'] },
    { name: 'ModalDialog', component: ModalDialog, prop: 'open', start: ['opacity-0', 'sm:scale-95'] },
]

let wrapper

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    }
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    seen = null
    document.body.innerHTML = ''
})

const frames = async (count) => {
    for (let i = 0; i < count; i++) await new Promise(resolve => requestAnimationFrame(resolve))
}

const mountDialog = ({ component, prop }, open) => {
    wrapper = mount(component, {
        props: { [prop]: open },
        slots: { default: () => h(Measuring) },
        attachTo: document.body,
    })
}

const panelClasses = () => [...document.querySelector('[id^="headlessui-dialog-panel"]').classList]

describe.each(dialogs)('$name', (dialog) => {
    it('is in its starting position when the content mounts', async () => {
        mountDialog(dialog, false)

        await wrapper.setProps({ [dialog.prop]: true })

        expect(seen.panel).toEqual(expect.arrayContaining(dialog.start))
        expect(seen.backdrop).toContain('opacity-0')
    })

    it('ends in place once it has opened', async () => {
        mountDialog(dialog, false)

        await wrapper.setProps({ [dialog.prop]: true })
        await frames(4)

        dialog.start.forEach(name => expect(panelClasses()).not.toContain(name))
    })

    it('opens too when it is created already open', async () => {
        mountDialog(dialog, true)

        await frames(4)

        expect(seen.panel).toEqual(expect.arrayContaining(dialog.start))
        dialog.start.forEach(name => expect(panelClasses()).not.toContain(name))
    })
})
