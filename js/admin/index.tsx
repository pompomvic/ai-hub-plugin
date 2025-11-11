import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

declare global {
  interface Window {
    AIHubWordPress?: Parameters<typeof App>[0]['data'];
    wp?: {
      i18n?: {
        __?: (text: string, domain?: string) => string;
      };
    };
  }
}

const container = document.getElementById('ai-hub-admin-app');
const payload = window.AIHubWordPress;

if (container && payload) {
  const root = createRoot(container);
  root.render(
    <StrictMode>
      <App data={payload} />
    </StrictMode>,
  );
}
