import { Capacitor } from '@capacitor/core';

export function useCapacitor() {
    const is_native = Capacitor.isNativePlatform();
    const platform = Capacitor.getPlatform(); // 'ios' | 'android' | 'web'

    return { is_native, platform };
}
