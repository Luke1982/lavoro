import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import jspreadsheet from 'jspreadsheet-ce'
import SpreadsheetComponent from '@/Components/UI/SpreadsheetComponent.vue'

const settle = (ms = 60) => new Promise((resolve) => setTimeout(resolve, ms))

function mountSheet(modelValue, extraProps = {}) {
    return mount(SpreadsheetComponent, {
        props: { modelValue, minDimensions: [4, 4], debounce: 10, ...extraProps },
        attachTo: document.body,
    })
}

function lastEmit(wrapper) {
    return wrapper.emitted('update:modelValue')?.at(-1)?.[0]
}

describe('SpreadsheetComponent', () => {
    it('captures values, styles, merges, column widths and headers', async () => {
        const wrapper = mountSheet(null)
        await settle(30)

        const worksheet = jspreadsheet.current
        worksheet.setValueFromCoords(0, 0, 'Post', true)
        worksheet.setStyle({ A1: 'background-color: cyan; font-weight: bold;' })
        worksheet.setMerge('C1', 2, 1)
        worksheet.setWidth(0, 220)
        worksheet.setHeader(1, 'Bedrag')
        await settle()

        const snapshot = lastEmit(wrapper)

        expect(snapshot.data[0][0]).toBe('Post')
        expect(snapshot.style.A1).toContain('background-color: cyan')
        expect(snapshot.style.A1).toContain('font-weight: bold')
        expect(snapshot.mergeCells.C1).toEqual([2, 1])
        expect(snapshot.columns[0].width).toBe(220)
        expect(snapshot.columns[1].title).toBe('Bedrag')

        wrapper.unmount()
    })

    it('restores formatting when remounted from a saved snapshot', async () => {
        const wrapper = mountSheet(null)
        await settle(30)

        const worksheet = jspreadsheet.current
        worksheet.setValueFromCoords(0, 0, 'Post', true)
        worksheet.setStyle({ A1: 'background-color: cyan;' })
        worksheet.setWidth(0, 220)
        worksheet.setHeader(1, 'Bedrag')
        await settle()

        const snapshot = lastEmit(wrapper)
        wrapper.unmount()

        const reloaded = mountSheet(snapshot)
        await settle(40)

        const restored = jspreadsheet.current
        expect(restored.getValueFromCoords(0, 0)).toBe('Post')
        expect(restored.getStyle('A1')).toContain('background-color: cyan')
        expect(restored.getWidth()[0]).toBe(220)
        expect(restored.getHeaders(true)[1]).toBe('Bedrag')

        reloaded.unmount()
    })

    it('keeps numeric cell values numeric', async () => {
        const wrapper = mountSheet(null)
        await settle(30)

        jspreadsheet.current.setValueFromCoords(1, 1, 4500, true)
        await settle()

        expect(lastEmit(wrapper).data[1][1]).toBe(4500)
        wrapper.unmount()
    })

    it('strips styles that are only the jspreadsheet default', async () => {
        const wrapper = mountSheet(null)
        await settle(30)

        jspreadsheet.current.setStyle({ A1: 'background-color: cyan;' })
        await settle()

        const snapshot = lastEmit(wrapper)
        expect(Object.keys(snapshot.style)).toEqual(['A1'])

        wrapper.unmount()
    })

    it.each([
        ['an empty column', null],
        ['a legacy bare array', [['Oud', 'formaat']]],
        ['a snapshot', { data: [['a']], style: { A1: 'color: red;' }, mergeCells: {}, columns: [] }],
        ['php empty maps', JSON.parse('{"data":[["a"]],"style":[],"mergeCells":[],"columns":[]}')],
    ])('does not save on mount for %s', async (_label, modelValue) => {
        const wrapper = mountSheet(modelValue)
        await settle(80)

        expect(wrapper.emitted('update:modelValue')).toBeFalsy()
        wrapper.unmount()
    })

    it('loads a legacy bare array without losing values', async () => {
        const wrapper = mountSheet([['Oud', 'formaat'], ['x', 1]])
        await settle(40)

        const worksheet = jspreadsheet.current
        expect(worksheet.getValueFromCoords(0, 0)).toBe('Oud')
        expect(worksheet.getValueFromCoords(1, 1)).toBe(1)

        wrapper.unmount()
    })

    it('flushes a pending edit when unmounted mid-debounce', async () => {
        const wrapper = mountSheet(null, { debounce: 600 })
        await settle(30)

        jspreadsheet.current.setValueFromCoords(0, 0, 'gewijzigd', true)
        await settle(50)

        expect(wrapper.emitted('update:modelValue')).toBeFalsy()

        wrapper.unmount()

        expect(lastEmit(wrapper).data[0][0]).toBe('gewijzigd')
    })

    it('emits a change when only formatting is altered', async () => {
        const wrapper = mountSheet({ data: [['a']], style: {}, mergeCells: {}, columns: [] })
        await settle(30)

        jspreadsheet.current.setStyle({ A1: 'background-color: yellow;' })
        await settle()

        expect(lastEmit(wrapper).style.A1).toContain('yellow')
        wrapper.unmount()
    })
})
