// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Handle digestforum subscription/tracking toggling.
 *
 * @module     mod_digestforum/digestforum_overview_toggle
 * @copyright  2025 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ForumEvents from 'mod_digestforum/digestforum_events';
import Notification from 'core/notification';
import {publish} from 'core/pubsub';
import {getString} from 'core/str';
import {add as addToast} from 'core/toast';
import Repository from 'mod_digestforum/repository';

/**
 * Register event listeners for the subscription/tracking toggles in the overview.
 * @param {HTMLElement} toggleElement The toggle root element
 * @param {Boolean} changeLabel Whether to update the visible label text
 */
function registerEventListeners(toggleElement, changeLabel = true) {
    toggleElement.addEventListener('change', () => {
        if (toggleElement.dataset.type === 'digestforum-subscription-toggle') {
            subscriptionToggleClickHandler(toggleElement, changeLabel);
        }
        if (toggleElement.dataset.type === 'digestforum-track-toggle') {
            trackToggleClickHanldler(toggleElement);
        }
    });
}

/**
 * Toggle subscription element click handler.
 *
 * @param {HTMLElement} toggleElement The toggle element that was clicked
 * @param {Boolean} changeLabel Whether to update the visible label text
 * @return {Promise<void>}
 */
async function subscriptionToggleClickHandler(toggleElement, changeLabel = true) {
    const digestforumId = toggleElement.dataset.digestforumid;
    const digestforumName = toggleElement.dataset.digestforumname;
    const userName = toggleElement.dataset.username;
    const newState = toggleElement.dataset.targetstate;
    if (!digestforumId || !newState) {
        return;
    }
    try {
        const context = await Repository.setForumSubscriptionState(digestforumId, newState);
        const newTargetState = !!context.userstate.subscribed;

        // The aria-label may only follow the toggle state when the visible label follows it too (changeLabel).
        // Otherwise the visible label stays static and a state-dependent aria-label would contradict it,
        // failing WCAG SC 2.5.3 Label in Name.
        let labelKey = '';
        let ariaLabelKey = '';
        if (changeLabel) {
            labelKey = newTargetState ? 'unsubscribe' : 'subscribe';
            ariaLabelKey = newTargetState ? 'unsubscribefromdigestforum' : 'subscribetodigestforum';
        }

        await updateSwitchState(
            toggleElement,
            newTargetState,
            labelKey,
            ariaLabelKey,
            digestforumName,
        );

        const feedbackMessage = await getString(
            newTargetState ? 'nowsubscribed' : 'nownotsubscribed',
            'mod_digestforum',
            newTargetState ? {digestforum: digestforumName} : {name: userName, digestforum: digestforumName},
        );
        addToast(feedbackMessage);

        const newLabel = await getString(newTargetState ? 'unsubscribediscussion' : 'subscribediscussion', 'mod_digestforum');
        publish(ForumEvents.ALL_SUBSCRIPTION_TOGGLED, {
            digestforumId: digestforumId,
            subscriptionState: newTargetState,
            newLabel: newLabel,
        });
    } catch (error) {
        Notification.exception(error);
    }
}

/**
 * Toggle track element click handler.
 *
 * @param {HTMLElement} toggleElement The toggle element that was clicked
 * @return {Promise<void>}
 */
async function trackToggleClickHanldler(toggleElement) {
    const digestforumId = toggleElement.dataset.digestforumid;
    const digestforumName = toggleElement.dataset.digestforumname;
    const newState = toggleElement.dataset.targetstate;
    if (!digestforumId || !newState) {
        return;
    }
    try {
        const context = await Repository.setForumTrackingState(digestforumId, newState);
        const newTargetState = !!context.userstate.tracked;

        await updateSwitchState(
            toggleElement,
            newTargetState,
            newTargetState ? 'trackingon' : 'trackingoff',
            newTargetState ? 'trackfordigestforum' : 'untrackfordigestforum',
            digestforumName,
        );

        const feedbackMessage = await getString(
            newTargetState ? 'trackedfordigestforum' : 'untrackedfordigestforum',
            'mod_digestforum',
            digestforumName,
        );
        addToast(feedbackMessage, {visuallyHidden: true});
    } catch (error) {
        Notification.exception(error);
    }
}

/**
 * Update the switch state of the toggle element.
 *
 * @param {HTMLElement} toggleElement The toggle element to update
 * @param {Boolean} newTargetState The new target state to set (true for subscribed, false for unsubscribed)
 * @param {string} stringKey The string key to retrieve the label text
 * @param {string} ariaLabelStringKey The string key to retrieve the aria-label text
 * @param {string} digestforumName The digestforum name for aria-label interpolation
 * @return {Promise<void>}
 */
async function updateSwitchState(toggleElement, newTargetState, stringKey, ariaLabelStringKey, digestforumName) {
    toggleElement.dataset.targetstate = newTargetState ? 0 : 1;
    if (stringKey) {
        const string = await getString(stringKey, 'mod_digestforum');
        const labelSelector = `label[for="${toggleElement.id}"] span`;
        const label = toggleElement.closest('td')?.querySelector(labelSelector) || document.querySelector(labelSelector);
        if (label) {
            label.textContent = string;
        }
    }

    if (ariaLabelStringKey && digestforumName) {
        const ariaLabelString = await getString(ariaLabelStringKey, 'mod_digestforum', digestforumName);
        toggleElement.setAttribute('aria-label', ariaLabelString);
    }
}

/**
 * Initialize the digestforum overview toggle functionality.
 *
 * @param {string} toggleSelector The CSS selector for the toggle element to initialize
 * @param {Boolean} changeLabel Whether to update the visible label text
 * @throws {Error} If no elements are found with the provided selector
 */
export const init = (toggleSelector, changeLabel = true) => {
    const toggleElement = document.querySelector(toggleSelector);
    if (!toggleElement) {
        // If the user cannot track/subscribe to any course digestforum, the toggle will not be present.
        return;
    }
    registerEventListeners(toggleElement, changeLabel);
};
