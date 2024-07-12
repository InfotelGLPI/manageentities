<?php
use Glpi\Event;

include('../../../inc/includes.php');
header('Content-Type: text/javascript');
$add_text = __('Add');
$modalUrl = PLUGIN_MANAGEENTITIES_WEBDIR.'/ajax/directhelpdesk.php';
if (Session::getCurrentInterface() == 'central') {
?>

$(window).load(function() {
    const newDiv = document.createElement('div');
    newDiv.classList.add('center');
    const newButton = document.createElement('button');
    const add_text = "<?php echo $add_text ?>";

    newButton.id ='launch-directhelpdesk-modal';
    newButton.textContent = add_text;
    newButton.classList.add('btn');
    newButton.classList.add('btn-sm');
    newButton.classList.add('btn-primary');
    newButton.classList.add('me-1');
    newDiv.appendChild(newButton);
    // Get the existing button element with the specific class
    const existingButton = document.querySelector('.trigger-fuzzy');

    // Insert the new button before the existing button
    existingButton.parentNode.insertBefore(newDiv, existingButton);

    const btn = document.getElementById('launch-directhelpdesk-modal');
    const page = document.querySelector("div[class='page']");
    const modalContainer = document.createElement('div');
    modalContainer.id = 'directhelpdeskmodalcontainer';
    page.append(modalContainer);
    btn.onclick = function() {
        // load modal if not present in the page
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
    }

    // Fermer la modal lorsque l'utilisateur clique en dehors de la modal
    window.onclick = function(event) {
        const modal = $("#directhelpdesk-modal");
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
})

<?php
}
