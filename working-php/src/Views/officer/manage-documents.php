<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Manage Document Route: <?php echo htmlspecialchars($document['tracking_code']); ?>
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>

<div class="py-2">
    <div class="mx-[20vh] sm:px-6 lg:px-8">
        
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Document Details -->
                    <div>
                        <h3 class="text-lg font-bold mb-2">Document Details</h3>
                        <?php 
                        $guestInfo = json_decode($document['guest_info'], true);
                        $guestName = htmlspecialchars($guestInfo['name'] ?? 'N/A');
                        $guestEmail = htmlspecialchars($guestInfo['email'] ?? 'N/A');
                        ?>
                        <p><strong>Submitter:</strong> <?php echo $guestName; ?> (<?php echo $guestEmail; ?>)</p>
                        <p><strong>Purpose:</strong> <?php echo htmlspecialchars($document['purpose_name'] ?? 'Unknown'); ?></p>
                        <p><strong>Submitted:</strong> <?php echo date('M d, Y h:i A', strtotime($document['created_at'])); ?></p>
                        <p><strong>Status:</strong> <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800"><?php echo ucfirst($document['status']); ?></span></p>
                    </div>

                    <!-- Route Management Form -->
                    <div>
                        <h3 class="text-lg font-bold mb-2">Manage Route</h3>
                        
                        <?php if ($document['status'] === 'pending'): ?>
                            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                                Drag and drop the boxes to re-order them, or add a new step from the dropdown below.
                            </p>
                            <form id="route-form" action="/documents/<?php echo $document['id']; ?>/finalize" method="POST">
                                <input type="hidden" name="final_route" id="final_route">
                                
                                <input type="hidden" name="pin" id="finalize-pin-input">

                                <!-- Horizontal Draggable List -->
                                <div class="overflow-x-auto pb-4">
                                    <div id="route-list" class="flex space-x-4 min-h-[8rem] bg-gray-50 dark:bg-gray-900 p-2 rounded-md">
                                        <?php if (!empty($document['suggested_route'])): ?>
                                            <?php foreach ($document['suggested_route'] as $index => $step): ?>
                                                <div class="route-step flex-shrink-0 w-40 p-4 bg-white dark:bg-gray-700 rounded-lg shadow-md cursor-move text-center relative mr-4 last:mr-0 select-none">
                                                    <button type="button" class="delete-step-btn w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xl absolute -top-1 -right-1 hover:bg-red-600">&times;</button>
                                                    <div class="font-bold text-lg text-accent-1 dark:text-accent-1-hover step-number"><?php echo $index + 1; ?></div>
                                                    <div class="step-name text-sm mt-1"><?php echo htmlspecialchars($step); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Add New Step UI -->
                                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <label for="department-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Add New Step</label>
                                    <div class="mt-1 flex rounded-md shadow-sm">
                                        <select id="department-select" class="block w-full rounded-none rounded-l-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-accent-1 focus:ring-accent-1 sm:text-sm">
                                            <option disabled selected>Choose a department...</option>
                                            <?php foreach ($departments as $department): ?>
                                                <option value="<?php echo htmlspecialchars($department['name']); ?>"><?php echo htmlspecialchars($department['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="add-step-btn" class="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-accent-1 focus:outline-none focus:ring-1 focus:ring-accent-1 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-accent-2 dark:border-gray-600">
                                            <span>Add</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-6">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light disabled:opacity-25 transition">
                                        Accept & Finalize Route
                                    </button>
                                </div>
                            </form>

                        <?php else: ?>
                            <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Management Unauthorized</h3>
                                        <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                            <p>This document is already <?php echo $document['status']; ?> and the route cannot be modified.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .sortable-ghost {
        opacity: 0.4;
        background: #a5b4fc;
    }
    .sortable-chosen {
        cursor: grabbing;
    }
</style>

<script src="/js/sortable.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const routeList = document.getElementById('route-list');
        const routeForm = document.getElementById('route-form');
        const hiddenInput = document.getElementById('final_route');
        const addStepBtn = document.getElementById('add-step-btn');
        const departmentSelect = document.getElementById('department-select');

        if (routeList) {
            new window.Sortable(routeList, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onUpdate: updateStepNumbers
            });

            function updateStepNumbers() {
                const steps = routeList.querySelectorAll('.route-step');
                steps.forEach((step, index) => {
                    step.querySelector('.step-number').textContent = index + 1;
                });
            }

            function createStepElement(departmentName) {
                const newStep = document.createElement('div');
                newStep.className = 'route-step flex-shrink-0 w-40 p-4 bg-white dark:bg-gray-700 rounded-lg shadow-md cursor-move text-center relative mr-4 last:mr-0 select-none';
                newStep.innerHTML = `
                    <button type="button" class="delete-step-btn w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xl absolute -top-1 -right-1 hover:bg-red-600">&times;</button>
                    <div class="font-bold text-lg text-accent-1 dark:text-accent-1-hover step-number"></div>
                    <div class="step-name text-sm mt-1">${departmentName}</div>
                `;
                return newStep;
            }

            addStepBtn.addEventListener('click', function() {
                const selectedDepartment = departmentSelect.value;
                if (!selectedDepartment || departmentSelect.selectedIndex === 0) {
                    return;
                }
                const newStep = createStepElement(selectedDepartment);
                routeList.appendChild(newStep);
                updateStepNumbers();
                departmentSelect.selectedIndex = 0;
            });

            // Event delegation for delete buttons
            routeList.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('delete-step-btn')) {
                    e.target.closest('.route-step').remove();
                    updateStepNumbers();
                }
            });

            routeForm.addEventListener('submit', function (e) {
                if (document.getElementById('finalize-pin-input').value !== '') {
                    return true;
                }

                e.preventDefault();
                
                const finalRouteOrder = Array.from(routeList.querySelectorAll('.step-name')).map(el => el.textContent.trim());
                if (finalRouteOrder.length === 0) {
                    alert("Please add at least one step to the route.");
                    return;
                }
                hiddenInput.value = JSON.stringify(finalRouteOrder);

                window.SigningModal.show(`Enter your Security PIN to finalize the route for: <?php echo htmlspecialchars($document['tracking_code']); ?>`, function(pin) {
                    document.getElementById('finalize-pin-input').value = pin;
                    routeForm.submit();
                });
            });
        }
    });
</script>

<?php require BASE_PATH . '/src/Views/partials/signing-modal.php'; ?>

<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
