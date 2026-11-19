import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { pageShell } from '@/lib/page-shell';

const appName = import.meta.env.VITE_APP_NAME || 'Elifba';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // The shell by the page's name (`lib/page-shell.ts`, docs/frontend.md
    // rule 1): the board, the remote, a room's screen and a room's page
    // draw their own frame.
    layout: (name) => {
        switch (pageShell(name)) {
            case 'none':
                return null;
            case 'auth':
                return AuthLayout;
            case 'settings':
                return [AppLayout, SettingsLayout];
            case 'app':
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
