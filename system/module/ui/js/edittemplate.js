$(document).ready(function()
{
    $.setAjaxForm('#editForm', function(response) 
    {
        if(response.result == 'fail')
        {
            bootbox.alert(response.warning);
        }
        if(response.result == 'success')
        {
            setTimeout(function()
            {
                location.href = response.locate;  
            }, 2000);
        }
    });

    $.setAjaxLoader('.okFile', '#ajaxModal');

    $('#resetBtn').click(function()
    {
       $('#content').val($('#rawContent').val());
       $('#editForm').submit();
    });
});