// Playwright CLI run-code probe. Use a synthetic Ready conversation, with Assist expanded.
async (page) => {
    const results = await page.evaluate(() => {
        const rectangles = element => Array.from(element.getClientRects()).filter(rect => rect.width > 0 && rect.height > 0);
        const controls = Array.from(document.querySelectorAll('[data-feedback-controls]')).filter(element => !element.hidden && rectangles(element).length);
        return controls.map(control => {
            const action = control.querySelector('[data-feedback-reject]').getBoundingClientRect();
            const select = control.querySelector('[data-feedback-usefulness]');
            const label = select.closest('label');
            const text = Array.from(label.childNodes).filter(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
            const labelRects = text.flatMap(node => {
                const range = document.createRange();
                range.selectNodeContents(node);
                return Array.from(range.getClientRects()).filter(rect => rect.width > 0 && rect.height > 0);
            });
            const field = select.getBoundingClientRect();
            const bounds = control.getBoundingClientRect();
            return {task: control.closest('[data-feedback-task]').dataset.feedbackTask,
                labelBelowAction: labelRects.length > 0 && labelRects.every(rect => rect.top >= action.bottom - 1),
                selectBelowLabel: labelRects.length > 0 && labelRects.every(rect => field.top >= rect.bottom - 1),
                selectWithinControls: field.left >= bounds.left - 1 && field.right <= bounds.right + 1};
        });
    });
    if (results.length === 0) throw new Error('No rendered feedback controls; prepare a synthetic suggestion and expand Assist first.');
    for (const result of results) {
        if (!result.labelBelowAction || !result.selectBelowLabel || !result.selectWithinControls) {
            throw new Error('Feedback labels or controls overlap: '+JSON.stringify(result));
        }
    }
    return {renderedControls: results.length, layout: results};
}
