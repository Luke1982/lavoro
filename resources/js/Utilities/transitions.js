/**
 * A row that appears in a form slides up into place, and drops away when it
 * goes. Bind it on a <Transition>: `<Transition v-bind="fadeUp">`.
 *
 * Inside a drawer or a modal this is the way to animate, not v-auto-animate:
 * that directive reads the computed style the moment it mounts, before the
 * dialog has put its panel off-screen, and the dialog then pops in instead of
 * sliding.
 */
export const fadeUp = {
    enterActiveClass: 'transition-all duration-300 ease-out',
    enterFromClass: 'opacity-0 translate-y-2',
    enterToClass: 'opacity-100 translate-y-0',
    leaveActiveClass: 'transition-all duration-200 ease-in',
    leaveFromClass: 'opacity-100 translate-y-0',
    leaveToClass: 'opacity-0 translate-y-2',
}
