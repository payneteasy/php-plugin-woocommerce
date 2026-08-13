document.addEventListener('DOMContentLoaded', function() {
    // Проверяем, находимся ли мы на странице редактирования заказа
    if (document.querySelector('body.post-type-shop_order')) {

        /*
         * *** Добавление функционала актуализации статуса оплаты
         */
        // Находим блоки с классом order_data_column
        const orderDataColumns = document.querySelectorAll('.order_data_column');

        /*
        Выбираем второй блок с классом order_data_column, если он существует.
        Если второго блока нет, используем первый блок.
        */
        let targetColumn = orderDataColumns[1]; // Индекс 1 соответствует второму блоку
        if (!targetColumn) {
            targetColumn = orderDataColumns[0]; // Индекс 0 соответствует первому блоку
        }

        // Создаем HTML-код для кнопки
        const buttonHtml = `
            <p class="form-field form-field-wide">
                <label for="payneteasy-button-check-status">Для проверки состояния заказа в платежной системе PAYNET, пожалуйста, нажмите кнопку:</label>
                <button class="button custom-action" id="payneteasy-button-check-status">Проверить статус</button>
            </p>
        `;

        // Проверяем условие добавления кнопки
        if (targetColumn) {
            // Добавляем кнопку в выбранный блок
            targetColumn.insertAdjacentHTML('beforeend', buttonHtml);

            const button = document.getElementById('payneteasy-button-check-status');

            if (button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();

                    const orderID = window.location.href.match(/post=([0-9]+)/)[1];

                    // Создаем объект FormData для сбора данных формы
                    const formData = new FormData();
                    formData.append('action', 'check_status');
                    formData.append('nonce', payneteasy_ajax_var.nonce);
                    formData.append('order_id', orderID);

                    // Выполняем AJAX-запрос к веб-хуку плагина wc-payneteasy
                    fetch(payneteasy_ajax_var.api_url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && confirm(data.message)) {
                            location.reload();
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Ошибка запроса:', error);
                        alert('Произошла ошибка при выполнении запроса.');
                    });
                });
            }
        }


        /**
         * *** Добавление функционала возрата
         */
        const originalButton = document.querySelector('.refund-actions button.do-manual-refund'); // Находим оригинальную кнопку

        if (originalButton) {
            // Создаем копию кнопки
            const copyButton = originalButton.cloneNode(true);
            copyButton.id = 'do-manual-refund-copy';
            copyButton.classList.remove('do-manual-refund'); // Удаляем класс do-manual-refund у копии

            // Скрываем оригинальную кнопку
            originalButton.style.display = 'none';

            // Вставляем копию кнопки после оригинальной кнопки
            originalButton.parentNode.insertBefore(copyButton, originalButton.nextSibling);

            // Функция для синхронизации атрибутов и текста копии с оригиналом
            function synchronizeButtons() {
               copyButton.innerHTML = originalButton.innerHTML;
               copyButton.disabled = originalButton.disabled;
               // При необходимости синхронизировать другие атрибуты
            }

            // Синхронизация при изменении атрибутов и текста оригинала
            const observer = new MutationObserver(synchronizeButtons);
            observer.observe(originalButton, { childList: true, attributes: true, subtree: true });

            // Добавление функционала возврата на копию кнопки
            copyButton.addEventListener('click', function(event) {
                event.preventDefault(); // Предотвращаем выполнение остальных действий

                // Получаем таблицу с классом woocommerce_order_items
                const table = document.querySelector('#woocommerce-order-items');

                if (table) {
                    // Идентификатор текущего заказа
                    const orderID = window.location.href.match(/post=([0-9]+)/)[1];

                    // Создаем объект FormData для сбора данных формы
                    const formData = new FormData();
                    formData.append('action', 'refund');
                    formData.append('nonce', payneteasy_ajax_var.nonce);
                    formData.append('order_id', orderID);

                    // Получаем все инпуты с именами, начинающимися с "refund_"
                    const refundInputs = table.querySelectorAll('input[name^="refund_"]');

                    // Добавляем данные в FormData
                    refundInputs.forEach(input => {
                        formData.append(input.name, input.value);
                    });

                    // Запрос подтверждения у пользователя
                    if (confirm('Выполнить возврат в платёжной системе PAYNETEASY?')) {
                        // Отправляем AJAX-запрос с помощью fetch
                        fetch(payneteasy_ajax_var.api_url, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(`Платёжная система PAYNETEASY: ${data.message}`);
                                originalButton.click();
                            } else {
                                if (confirm(`Платёжная система PAYNETEASY: ${data.message}. Продолжит возврат в WordPress?`)) {
                                    originalButton.click();
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Ошибка запроса:', error);
                            if (confirm('Произошла ошибка при возврате в платёжной системе PAYNET. Продолжит возврат в WordPress?')) {
                                originalButton.click();
                            }
                        });
                    } else {
                        originalButton.click();
                    }

                } else {
                    console.error('Таблица с классом woocommerce_order_items не найдена.');
                }
            });
        }
    }
});
