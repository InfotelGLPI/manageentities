<?php
use Glpi\Event;

include('../../../../inc/includes.php');
header('Content-Type: text/javascript');
$add_text = __('Add');
$add_text_collapsed = __('A');
$modalUrl = PLUGIN_MANAGEENTITIES_WEBDIR.'/ajax/directhelpdesk.php';
if (Session::getCurrentInterface() == 'central') {
?>

$(window).on("load", function() {
    const newDiv = document.createElement('div');
    const newButton = document.createElement('button');
    const add_text = "<?php echo $add_text ?>";
    const add_text_collapsed = "<?php echo $add_text_collapsed ?>";

    newButton.id = 'launch-directhelpdesk-modal';
    newButton.classList.add('btn', 'btn-sm', 'btn-primary', 'me-1');

    function updateButtonState() {
        const collapsed = $('body').hasClass('navbar-collapsed');
        if (collapsed) {
            newButton.style.marginLeft = '0px';
            newButton.textContent = add_text_collapsed;
        } else {
            newButton.style.marginLeft = '70px';
            newButton.textContent = add_text;
        }
    }

    // état initial
    updateButtonState();

    newDiv.appendChild(newButton);

    // Insérer avant le bouton existant
    const existingButton = document.querySelector('.trigger-fuzzy');
    existingButton.parentNode.insertBefore(newDiv, existingButton);

    // Préparer la modal
    const page = document.querySelector("div.page");
    const modalContainer = document.createElement('div');
    modalContainer.id = 'directhelpdeskmodalcontainer';
    page.append(modalContainer);

    // clic sur le bouton
    newButton.addEventListener('click', function() {
        if (!document.getElementById('directhelpdesk-modal')) {
            $('#directhelpdeskmodalcontainer').load(
                '<?php echo $modalUrl ?>',
                function() {
                    $("#directhelpdesk-modal").modal('show');
                }
            );
        } else {
            $("#directhelpdesk-modal").modal('show');
        }
    });

    // Fermer la modal si clic en dehors
    $(document).on('click', function(event) {
        const modal = document.getElementById('directhelpdesk-modal');
        if (modal && event.target === modal) {
            $(modal).modal('hide');
        }
    });

    // Gérer toggle du menu
    $('.reduce-menu').on('click', function() {
        updateButtonState();
    });
});

<?php
}
