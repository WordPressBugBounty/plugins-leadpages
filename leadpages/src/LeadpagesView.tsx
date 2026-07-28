import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { LoginStatusResponse, NovaStatusResponse } from './types/api';
import LandingPages from './components/LandingPages';
import Login from './components/Login';

const LeadpagesView: React.FC = () => {
    const [isLoggedIn, setIsLoggedIn] = useState(false);
    const [checkingStatus, setCheckingStatus] = useState(true);

    // An install may be connected to Classic or to the new Leadpages (Nova). Treat the user as
    // logged in if either connection is present.
    const checkLoginStatus = async () => {
        try {
            const [classicStatus, novaStatus] = (await Promise.all([
                apiFetch({ path: '/leadpages/v1/oauth2/status' }).catch(() => ({ isLoggedIn: false })),
                apiFetch({ path: '/leadpages/v1/nova/status' }).catch(() => ({ isConnected: false })),
            ])) as [LoginStatusResponse, NovaStatusResponse];
            setIsLoggedIn(Boolean(classicStatus?.isLoggedIn) || Boolean(novaStatus?.isConnected));
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

    useEffect(() => {
        checkLoginStatus();
    }, []);

    if (checkingStatus) {
        return null;
    }

    if (!isLoggedIn) {
        return <Login onLoginSuccess={handleAuthenticationChange} />;
    }

    return <LandingPages onAuthenticationError={handleAuthenticationChange} />;
};

export default LeadpagesView;

window.addEventListener('load', () => render(<LeadpagesView />, document.getElementById('leadpages-page-root')));
