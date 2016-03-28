/**
 * Basic editor with context switch
 */
window.richTextLookups = {};
$(document).ready(function() {

    window.richTextUpdate = function(_name, show_after) {
        var prefix = $("#bridge_main_menu_left").data("linkprefix");
        $.post(
            prefix + "/ajax/rich_text_preview",
            {
                "format": $("#" + richTextLookups[_name]).val(),
                "body": $("#rich_text_" + _name).val()
            },
            function (response) {
                if (typeof(response) === "string") {
                    response = $.parseJSON(response);
                }
                if (response.status === "OK") {
                    $("#rich_text_" + _name + "_preview")
                        .html(response.body);
                    if (show_after) {
                        $("#rich_text_" + _name + "_text").hide();
                        $("#rich_text_" + _name + "_preview").show();
                    }
                } else {
                    alert(status.message);
                }
            }
        );
    };

    window.richTextWrapper = function(name, format_name) {

        richTextLookups[name] = format_name;

        $("#rich_text_" + name +"_edit_tab").addClass("active_tab");

        $("#rich_text_" + name +"_edit_tab").click(function(e) {
            var _name = $(this).parent().parent().data('name');
            $(this).parent().each(function () {
                $("a").removeClass("active_tab");
            });
            $(this).addClass("active_tab");
            $("#rich_text_" + _name + "_text").show();
            $("#rich_text_" + _name + "_preview").hide();
        });

        $("#"+format_name).on('change', function(e) {
            for (k in richTextLookups) {
                if ($(this).attr('id') == richTextLookups[k]) {
                    if ($("#rich_text_" + name + "_preview").is(':visible')) {
                        return richTextUpdate(k, false);
                    }
                    return;
                }
            }
        });

        $("#rich_text_" + name +"_preview_tab").click(function(e) {
            var _name = $(this).parent().parent().data('name');
            $(this).parent().each(function () {
                $("a").removeClass("active_tab");
            });
            $(this).addClass("active_tab");
            richTextUpdate(_name, true);
        });


        $("#rich_text_" + name + "_text").show();
        $("#rich_text_" + name + "_preview").hide();

    };

});