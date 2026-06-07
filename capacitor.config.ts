import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'nl.lavoro.fsm',
  appName: 'Lavoro',
  webDir: 'capacitor-launcher',
  plugins: {
    BackgroundGeolocation: {
      backgroundMessage: 'Lavoro volgt uw locatie voor servicebezoeken.',
      backgroundTitle: 'Locatie actief',
      requestPermissions: true,
      stale: false,
      distanceFilter: 50,
    },
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
  },
};

export default config;
