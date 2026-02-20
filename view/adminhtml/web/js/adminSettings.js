require(['jquery'], function($){
    var $m = $.noConflict();
    $m(document).ready(function() {

        $m("#lk_check1").change(function(){
            if($("#lk_check2").is(":checked") && $("#lk_check1").is(":checked")){
                $("#activate_plugin").removeAttr('disabled');
            }
        });

        $m("#lk_check2").change(function(){
            if($("#lk_check2").is(":checked") && $("#lk_check1").is(":checked")){
                $("#activate_plugin").removeAttr('disabled');
            }
        });

        $m(".navbar a").click(function() {
            $id = $m(this).parent().attr('id');
            setactive($id);
            $href = $m(this).data('method');
            voiddisplay($href);
        });

        $m(".btn-link").click(function() {
            $m(".show_info").slideUp("slow");
            if (!$m(this).next("div").is(':visible')) {
                $m(this).next("div").slideDown("slow");
            }
        });
        $m('#idpguide').on('change', function() {
            var selectedIdp =  jQuery(this).find('option:selected').val();
            $m('#idpsetuplink').css('display','inline');
            $m('#idpsetuplink').attr('href',selectedIdp);
        });
        $m("#mo_saml_add_shortcode").change(function(){
            $m("#mo_saml_add_shortcode_steps").slideToggle("slow");
        });
        $m('#error-cancel').click(function() {
            $error = "";
            $m(".error-msg").css("display", "none");
        });
        $m('#success-cancel').click(function() {
            $success = "";
            $m(".success-msg").css("display", "none");
        });
        $m('#cURL').click(function() {
            $m(".help_trouble").click();
            $m("#cURLfaq").click();
        });
        $m('#help_working_title1').click(function() {
            $m("#help_working_desc1").slideToggle("fast");
        });
        $m('#help_working_title2').click(function() {
            $m("#help_working_desc2").slideToggle("fast");
        });

    });
});

function setactive($id) {
    $m(".navbar-tabs>li").removeClass("active");
    $id = '#' + $id;
    $m($id).addClass("active");
}

function voiddisplay($href) {
    $m(".page").css("display", "none");
    $m($href).css("display", "block");
}

function mosp_valid(f) {
    !(/^[a-zA-Z?,.\(\)\/@ 0-9]*$/).test(f.value) ? f.value = f.value.replace(/[^a-zA-Z?,.\(\)\/@ 0-9]/, '') : null;
}

function showTestWindow() {
    var myWindow = window.open(testURL + "?option=mooauth_test", "TEST OAUTH", "scrollbars=1 width=800, height=600");
}

function mooauth_upgradeform(planType){
    jQuery('#requestOrigin').val(planType);
    jQuery('#mocf_loginform').submit();
}


function supportAction(){
}


//update---instead of checkbox using div

var registeredElement = document.getElementById("registered");
if (registeredElement) {
    registeredElement.addEventListener("click", ifUserRegistered, false);
}
function ifUserRegistered() {
  var inputField = document.getElementById("myInput");
  var confirmPasswordElement = jQuery('#confirmPassword');
  var checkAllTopicCheckBoxes = document.getElementById('registered');
  var registerLoginButton = document.getElementById('registerLoginButton');
  var register_login = document.getElementById('register_login');
  const forget=document.getElementById("forget_pass");
  if (confirmPasswordElement.css('display') === 'none') {
    //login time
    confirmPasswordElement.css('display', 'block');
    registerLoginButton.value = "Register"; 
    checkAllTopicCheckBoxes.textContent = 'Already Registered ? Click here to Login';
    register_login.textContent = 'Register with miniOrange';
    confirmPasswordElement.prop('required', false); 
    forget.style.display = 'none';
    inputField.setAttribute("required", "required");
   // inputField.removeAttribute("required");
    
} else {
    //register time
    confirmPasswordElement.css('display', 'none');
    registerLoginButton.value = "Login"; 
    checkAllTopicCheckBoxes.textContent = 'Sign Up';
    register_login.textContent = 'Login with miniOrange';
    confirmPasswordElement.prop('required', true);
    forget.style.display = 'block'; 
    if (inputField.hasAttribute("required")) {
        inputField.removeAttribute("required");
    }
 //   inputField.removeAttribute("required");
   // inputField.setAttribute("required", "required");
  }
}

function hide_show_GrantType(element) {
    document.getElementById("hideValuesOnSelect").style.display = element.value == "implicit_grant" ? "block" : "none";
    document.getElementById("hideValuesOnSelect").style.display = element.value == "hybrid_grant" ? "block" : "none";
    document.getElementById("hideValuesOnSelect").style.display = element.value == "password_grant" ? "block" : "none";
    document.getElementById("hideValuesOnSelect").style.display = element.value == "client_credentials_grant" ? "block" : "none";
    document.getElementById("hideValuesOnSelect").style.display = element.value == "authorization_code" ? "none" : "block";
 }
 function ShowHideDiv() {
    var chk_url = document.getElementById("chk_url");

      var endpoint_url = document.getElementById("endpoint_url");
       endpoint_url.style.display = chk_url.checked ? "block" : "none";


          var chk_manual = document.getElementById("chk_manual");


          var mo_oauth_authorize_url= document.getElementById("mo_oauth_authorize_url");
   mo_oauth_authorize_url.style.display = chk_manual.checked ? "block" : "none";


}
