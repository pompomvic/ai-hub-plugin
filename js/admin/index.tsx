import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App, { BootPayload } from './App';

declare global {
  interface Window {
    AIHubWordPress?: BootPayload;
    wp?: {
      i18n?: {
        __?: (text: string, domain?: string) => string;
      };
    };
  }
}

const container = document.getElementById('ai-hub-wordpress-admin');
const payload = window.AIHubWordPress;

if (container && payload) {
  const root = createRoot(container);
  root.render(
    <StrictMode>
      <App data={payload} />
    </StrictMode>,
  );
}
