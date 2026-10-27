import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { LegacyImportStatusResponse, LoginStatusResponse, NovaStatusResponse } from './types/api';
import LandingPages from './components/LandingPages';
import LegacyImport from './components/LegacyImport';
import Login from './components/Login';

const LeadpagesView: React.FC = () => {
    const [isLoggedIn, setIsLoggedIn] = useState(false);
    const [checkingStatus, setCheckingStatus] = useState(true);
    // Pages left by the old Leadpages Connector plugin, which a Nova connection can republish.
    const [legacyImport, setLegacyImport] = useState<LegacyImportStatusResponse | null>(null);
    const [showLegacyImport, setShowLegacyImport] = useState(false);

    // An install may be connected to Classic or to the new Leadpages (Nova). Treat the user as
    // logged in if either connection is present.
    const checkLoginStatus = async () => {
        try {
            const [classicStatus, novaStatus] = (await Promise.all([
                apiFetch({ path: '/leadpages/v1/oauth2/status' }).catch(() => ({ isLoggedIn: false })),
                apiFetch({ path: '/leadpages/v1/nova/status' }).catch(() => ({ isConnected: false })),
            ])) as [LoginStatusResponse, NovaStatusResponse];
            setIsLoggedIn(Boolean(classicStatus?.isLoggedIn) || Boolean(novaStatus?.isConnected));
            if (novaStatus?.isConnected) {
                const status = (await apiFetch({ path: '/leadpages/v1/legacy-import' }).catch(
                    () => null
                )) as LegacyImportStatusResponse | null;
                // legacyPageCount counts only old pages nothing on the site serves yet, so a site moved
                // over by hand never runs the import. Opening the screen with pages pending and no stored
                // result starts the import, unless the last attempt did not finish; once it has run, the
                // result stays one click away.
                if (status?.legacyPageCount || status?.result) {
                    setLegacyImport(status);
                    setShowLegacyImport(Boolean(status.legacyPageCount) && !status.result);
                }
            }
        } catch (e) {
            setIsLoggedIn(false);
        } finally {
            setCheckingStatus(false);
        }
    };

    // When the state of the users authenticated status changes (such as a after
    // successful login or an authentication error), reload the page so the
    // submenu items that are gated by the auth status can be rendered
    const handleAuthenticationChange = () => {
        window.location.reload();
    };

    // Re-read the status so a reopened import screen shows the latest result.
    const handleShowLegacyImport = async () => {
        const status = (await apiFetch({ path: '/leadpages/v1/legacy-import' }).catch(
            () => legacyImport
        )) as LegacyImportStatusResponse | null;
        setLegacyImport(status);
        setShowLegacyImport(true);
    };

    const handleLegacyImportDone = () => {
        setShowLegacyImport(false);
    };

    useEffect(() => {
        checkLoginStatus();
    }, []);

    if (checkingStatus) {
        return null;
    }

    if (!isLoggedIn) {
        return <Login onLoginSuccess={handleAuthenticationChange} />;
    }

    if (legacyImport && showLegacyImport) {
        return (
            <LegacyImport
                legacyPageCount={legacyImport.legacyPageCount}
                initialResult={legacyImport.result}
                initialFailure={legacyImport.failure}
                onDone={handleLegacyImportDone}
                onAuthenticationError={handleAuthenticationChange}
            />
        );
    }

    return (
        <LandingPages
            onAuthenticationError={handleAuthenticationChange}
            onShowLegacyImport={legacyImport ? handleShowLegacyImport : undefined}
        />
    );
};

export default LeadpagesView;

window.addEventListener('load', () => render(<LeadpagesView />, document.getElementById('leadpages-page-root')));
