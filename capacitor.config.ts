import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The server is not set here but when the app is built: MainActivity loads
 * BuildConfig.SERVER_URL, https://app.lavorofsm.nl unless Gradle is given
 * -PlavoroServerUrl. webDir only holds the page shown when that server cannot
 * be reached. See docs/install/android.md.
 */
const config: CapacitorConfig = {
  appId: 'nl.lavoro.fsm',
  appName: 'Lavoro',
  webDir: 'capacitor-launcher',
  plugins: {
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
  },
};

export default config;
