import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';

import { unlock } from './lock-unlock';

export const REST_NAMESPACE = 'prc-revisions/v1';

/**
 * Hook to access the currently selected revision ID from the editor's
 * private API via unlock(). Returns null when not in revisions browsing mode
 * or when the private API is unavailable.
 */
export function useCurrentRevisionId() {
	return useSelect((select) => {
		try {
			const unlockedSelectors = unlock(select(editorStore));
			if (typeof unlockedSelectors.getCurrentRevisionId === 'function') {
				return unlockedSelectors.getCurrentRevisionId() ?? null;
			}
		} catch {
			// Private API unavailable — graceful degradation.
		}
		return null;
	}, []);
}

/**
 * Fetch fork info from the REST API. Shared across fork-aware components.
 *
 * @param {number} postId Post ID.
 * @return {{ forkInfo: Object|null, isLoading: boolean }} Fork info from API or { role: 'none' } on error; isLoading true until fetch completes.
 */
export function useForkInfo(postId) {
	const [forkInfo, setForkInfo] = useState(null);
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		if (!postId) {
			setForkInfo(null);
			setIsLoading(false);
			return;
		}
		setIsLoading(true);
		apiFetch({ path: `/${REST_NAMESPACE}/fork-info/${postId}` })
			.then(setForkInfo)
			.catch(() => setForkInfo({ role: 'none' }))
			.finally(() => setIsLoading(false));
	}, [postId]);

	return { forkInfo, isLoading };
}
