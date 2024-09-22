import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { info } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import './login.css';
import { getErrorMessage } from '../../utils/api';
import { WPResponseError } from '../../types/api';
import leadpagesLogo from '../../../public/lp-logo-icon-34x24.png';
import loginPromo from '../../../public/login-promo-1206x1614.png';

const SIGN_UP_URL =
    'https://www.leadpages.com/pricing?utm_campaign=Trial%20to%20Paid&utm_source=wordpress&utm_medium=wordpress&utm_term=wordpress';
const OAUTH_CHANNEL = 'oauth_channel';

type OAuthChannelData = {
    success: boolean;
    error: string;
};

export interface Props {
    onLoginSuccess: () => void;
}

const Login: React.FC<Props> = ({ onLoginSuccess }) => {
    const [error, setError] = useState('');

    const handleLoginClick = async () => {
        try {
            const url = (await apiFetch({
                path: 'leadpages/v1/oauth2/authorize',
            })) as string;
            window.open(url, 'oauth2Popup', 'width=800,height=800');
        } catch (err) {
            setError(getErrorMessage(err as WPResponseError));
            return;
        }

        // Await the result of logging in and handle success or error responses
        const oauthChannel = new BroadcastChannel(OAUTH_CHANNEL);
        oauthChannel.onmessage = (event: MessageEvent<OAuthChannelData>) => {
            if (event.data.success === true) {
                onLoginSuccess();
                oauthChannel.close();
            } else {
                setError(event.data.error);
                oauthChannel.close();
            }
        };
    };

    const handleSignUp = () => {
        window.open(SIGN_UP_URL, '_blank');
    };

    return (
        <div className="login-root">
            <div className="left-container">
                <img src={leadpagesLogo} alt="Leadpages Logo" />
                <h1 className="login-title">Log in to Leadpages to start publishing to WordPress</h1>
                {error && (
                    <div className="lp-alert lp-alert-error alert">
                        <div className="lp-alert-icon lp-error-icon rotate-180">{info}</div>
                        <div className="lp-alert-message lp-message-error">{error}</div>
                    </div>
                )}
                <Button className="marketing-button contained" onClick={handleLoginClick}>
                    Log In
                </Button>
            </div>
            <div className="right-container">
                <img src={loginPromo} alt="Leadpages Promo" className="promo-image" />
                <Button className="marketing-button outlined sign-up-button" onClick={handleSignUp}>
                    Sign Up Free
                </Button>
            </div>
        </div>
    );
};

export default Login;
