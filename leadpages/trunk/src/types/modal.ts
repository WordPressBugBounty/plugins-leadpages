import { LandingPage } from './api';

export type Variants = 'publish' | 'edit' | 'unpublish';

export interface ModalState {
    show: boolean;
    variant: Variants | null;
    page: LandingPage | null;
}
