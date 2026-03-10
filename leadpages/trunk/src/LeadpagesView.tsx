import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { LoginStatusResponse } from './types/api';
import LandingPages from './components/LandingPages';
import Login from './components/Login';

const LeadpagesView: React.FC = () => {
    const [isLoggedIn, setIsLoggedIn] = useState(false);
    const [checkingStatus, setCheckingStatus] = useState(true);

    const checkLoginStatus = async () => {
        try {
            const response = (await apiFetch({ path: '/leadpages/v1/oauth2/status' })) as LoginStatusResponse;
            setIsLoggedIn(response.isLoggedIn);
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
