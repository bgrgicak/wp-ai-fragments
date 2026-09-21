import { App } from '@modelcontextprotocol/ext-apps';
import './fragment-viewer.css';

const title = document.getElementById('fragment-title');
const description = document.getElementById('fragment-description');
const openButton = document.getElementById('fragment-open');
const expandButton = document.getElementById('fragment-expand');
const editor = document.getElementById('fragment-editor');
const fieldLabel = document.getElementById('fragment-field-label');
const singleValue = document.getElementById('fragment-value-single');
const contentValue = document.getElementById('fragment-value');
const saveButton = document.getElementById('fragment-save');
const fallback = document.getElementById('fragment-fallback');
const linkFallback = document.getElementById('fragment-link-fallback');
const urlField = document.getElementById('fragment-url');
const status = document.getElementById('fragment-status');
let fragmentUrl = '';
let postId = 0;
let field = '';

const resultError = (result) => {
	const message = result?.content?.find((item) => item.type === 'text')?.text;
	return message || 'WordPress could not save this field.';
};

const showResult = (result) => {
	const data = result?.structuredContent;

	if (!data || typeof data.url !== 'string') {
		title.textContent = 'Fragment unavailable';
		description.textContent = 'WordPress did not return a fragment URL.';
		openButton.hidden = true;
		expandButton.hidden = true;
		editor.hidden = true;
		fallback.hidden = true;
		linkFallback.hidden = true;
		status.textContent = '';
		return;
	}

	title.textContent = data.label || data.fragment_id || 'WordPress UI fragment';
	fragmentUrl = data.url;
	urlField.value = fragmentUrl;
	openButton.hidden = false;
	expandButton.hidden = false;

	if (data.mode === 'post-field' && Number.isInteger(data.post_id) && typeof data.field === 'string') {
		postId = data.post_id;
		field = data.field;
		fieldLabel.textContent = title.textContent;
		description.textContent = 'Edit this WordPress field directly in the MCP Apps component.';
		singleValue.hidden = field !== 'title';
		contentValue.hidden = field !== 'content';
		const control = field === 'title' ? singleValue : contentValue;
		control.value = typeof data.value === 'string' ? data.value : '';
		control.disabled = !data.editable;
		saveButton.hidden = !data.editable;
		editor.hidden = false;
		fallback.hidden = true;
		status.textContent = data.editable ? 'Ready to edit.' : 'You can view this field, but your WordPress user cannot edit it.';
		return;
	}

	editor.hidden = true;
	fallback.hidden = false;
	description.textContent = 'This fragment is available on its focused WordPress admin screen.';
	status.textContent = 'Open the focused WordPress URL to view or edit it.';
};

const app = new App({ name: 'WP AI Fragments Viewer', version: '0.2.0' });
app.ontoolresult = showResult;
editor.addEventListener('submit', async (event) => {
	event.preventDefault();
	const control = field === 'title' ? singleValue : contentValue;
	saveButton.disabled = true;
	status.textContent = 'Saving…';

	try {
		const result = await app.callServerTool({
			name: 'ui-update-post-field',
			arguments: { post_id: postId, field, value: control.value },
		});
		if (result.isError) {
			throw new Error(resultError(result));
		}
		const saved = result.structuredContent;
		if (saved && typeof saved.value === 'string') {
			control.value = saved.value;
		}
		status.textContent = 'Saved in WordPress.';
	} catch (error) {
		status.textContent = error instanceof Error ? error.message : 'WordPress could not save this field.';
	} finally {
		saveButton.disabled = false;
	}
});
expandButton.addEventListener('click', async () => {
	try {
		await app.requestDisplayMode({ mode: 'fullscreen' });
	} catch (error) {
		status.textContent = 'This client could not expand the fragment. You can still edit it here or open it in WordPress.';
	}
});
openButton.addEventListener('click', async () => {
	if (fragmentUrl) {
		try {
			if (window.openai?.openExternal) {
				await window.openai.openExternal({ href: fragmentUrl, redirectUrl: false });
			} else {
				await app.openLink({ url: fragmentUrl });
			}
		} catch (error) {
			linkFallback.hidden = false;
			urlField.focus();
			urlField.select();
			status.textContent = 'This client blocked localhost navigation. Copy the focused URL shown below into your browser.';
		}
	}
});
await app.connect();
