/**
 * Manage document route page (src/Views/officer/manage-documents.php): the route builder (drag to re-order, add and
 * remove steps) and the Security PIN prompt before the route is finalized.
 *
 * Server data: the form's data-sign-message (the prompt text). Declining a document (the dialog, its PIN prompt) is
 * handled by the shared modal module (.open-modal-btn, .close-modal-btn) and components/sign-actions.js.
 */
DtsPage.onLoad('[data-page="manage-documents"]', function initManageDocumentsPage() {
    'use strict';

    const routeList = document.getElementById('route-list');
    const routeForm = document.getElementById('route-form');
    // A page whose document is no longer pending has no route builder
    if (!routeList || !routeForm) return;

    const finalRouteInput = document.getElementById('final_route');
    const finalizePinInput = document.getElementById('finalize-pin-input');
    const addStepButton = document.getElementById('add-step-btn');
    const departmentSelect = document.getElementById('department-select');

    // Number the steps again after a drag, an addition or a removal
    function updateStepNumbers() {
        routeList.querySelectorAll('.route-step').forEach((step, index) => {
            step.querySelector('.step-number').textContent = index + 1;
        });
    }

    // Build the box of a new step; the unit name is inserted as text, never parsed as HTML
    function createStepElement(departmentName) {
        const newStep = document.createElement('div');
        newStep.className = 'route-step flex-shrink-0 w-40 p-4 bg-white dark:bg-gray-700 rounded-lg shadow-md cursor-move text-center relative mr-4 last:mr-0 select-none';
        newStep.innerHTML = `
            <button type="button" class="delete-step-btn w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xl absolute -top-1 -right-1 hover:bg-red-600">&times;</button>
            <div class="font-bold text-lg text-accent-1 dark:text-accent-1-hover step-number"></div>
            <div class="step-name text-sm mt-1"></div>
        `;
        newStep.querySelector('.step-name').textContent = departmentName;

        // The × button names the step it removes
        newStep.querySelector('.delete-step-btn').setAttribute('aria-label', 'Remove step: ' + departmentName);
        return newStep;
    }

    // Drag and drop re-ordering
    const sortable = new window.Sortable(routeList, {
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onUpdate: updateStepNumbers,
    });

    // Add the chosen department as the last step
    addStepButton.addEventListener('click', () => {
        const selectedDepartment = departmentSelect.value;
        if (!selectedDepartment || departmentSelect.selectedIndex === 0) return;

        routeList.appendChild(createStepElement(selectedDepartment));
        updateStepNumbers();
        departmentSelect.selectedIndex = 0;
    });

    // Event delegation for the remove buttons (steps added later work without re-binding)
    routeList.addEventListener('click', (event) => {
        const deleteButton = event.target.closest('.delete-step-btn');
        if (!deleteButton) return;

        deleteButton.closest('.route-step').remove();
        updateStepNumbers();
    });

    // Finalizing needs at least one step and the Security PIN
    routeForm.addEventListener('submit', (event) => {
        // The PIN is in: send the form
        if (finalizePinInput.value !== '') return;

        event.preventDefault();
        const finalRouteOrder = Array.from(routeList.querySelectorAll('.step-name')).map((element) => element.textContent.trim());
        if (finalRouteOrder.length === 0) {
            alert('Please add at least one step to the route.');
            return;
        }

        finalRouteInput.value = JSON.stringify(finalRouteOrder);
        window.SigningModal.submitWithPin(routeForm, finalizePinInput, routeForm.dataset.signMessage, event.submitter);
    });

    return function destroySortable() {
        sortable.destroy();
    };
});
