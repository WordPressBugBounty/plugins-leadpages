import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { info } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import './login.css';
import { getErrorMessage } from '../../utils/api';
import { WPResponseError } from '../../types/api';
import leadpagesLogo from '../../../public/leadpages-logo.png';

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

    // Start an OAuth connect flow against the given authorize endpoint. Both Classic and the new
    // Leadpages (Nova) use the same PKCE popup + BroadcastChannel flow; only the endpoint differs.
    const startConnect = async (authorizePath: string) => {
        try {
            const url = (await apiFetch({
                path: authorizePath,
            })) as string;
            window.open(url, 'oauth2Popup', 'width=800,height=800');
        } catch (err) {
            setError(getErrorMessage(err as WPResponseError));
            return;
        }

        // Await the result of connecting and handle success or error responses
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

    const handleLoginClick = () => startConnect('leadpages/v1/oauth2/authorize');

    const handleNovaConnectClick = () => startConnect('leadpages/v1/nova/authorize');

    const handleSignUp = () => {
        window.open(SIGN_UP_URL, '_blank');
    };

    return (
        <div className="login-root">
            <div className="left-container">
                <img src={leadpagesLogo} alt="Leadpages Logo" />
                <h1 className="login-title">Connect Leadpages to start publishing to WordPress</h1>
                {error && (
                    <div className="lp-alert lp-alert-error alert">
                        <div className="lp-alert-icon lp-error-icon rotate-180">{info}</div>
                        <div className="lp-alert-message lp-message-error">{error}</div>
                    </div>
                )}
                <Button className="marketing-button contained" onClick={handleLoginClick}>
                    Log in to Classic Leadpages
                </Button>
                <Button className="marketing-button outlined nova-connect-button" onClick={handleNovaConnectClick}>
                    Connect the new Leadpages
                </Button>
            </div>
            <div className="right-container">
                <div className="login-promo-panel">
                    <h2 className="promo-headline">Turn WordPress visitors into leads</h2>
                    <p className="promo-subtext">
                        Build high-converting landing pages in Leadpages and publish them right on your own
                        WordPress domain.
                    </p>
                    <ul className="promo-benefits">
                        <li>Publish to any URL on your site &mdash; no DNS changes</li>
                        <li>Edit in Leadpages; updates go live automatically</li>
                        <li>Conversion-optimized templates + built-in analytics</li>
                    </ul>
                    <Button className="marketing-button sign-up-button" onClick={handleSignUp}>
                        Start for free
                    </Button>
                </div>
            </div>
        </div>
    );
};

export default Login;
