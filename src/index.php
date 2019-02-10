<html lang="en">
<head>
    <title>Cloud-bets demo stuff..</title>

    <meta charset="utf-8">

    <link rel="stylesheet" href="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.0/jquery.min.js"></script>
    <script src="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script>

</head>
<body>
<div class="container">
<h1>Cloud-beds test stuff, rent intervals.</h1><hr />

    <table id="intervals" class="table table-striped">
        <thead><tr>
            <th><a href="#">
                <span id="truncate_db" class="glyphicon glyphicon-remove-circle" title="Clear all intervals"></span>
            </a></th>
            <th><a href="#">
                <span class="glyphicon glyphicon-plus-sign" title="Add interval"></span>
            </a></th>
            <th>date_start <span class="glyphicon glyphicon-arrow-down"></span></th>
            <th>date_end</th>
            <th>price</th>
        </tr></thead>
    </table>
</div>
<div id="add_interval_form" style="margin-left: 240px; display: none;">

    <form action="api.php" role="form">
        <h2 id="caption">Lets add interval.</h2><hr />

        <div class="form-group">
            <label for="date_start">Date start:</label>
            <input type="date" id="date_start" name="date_start" />
        </div>

        <div class="form-group">
            <label for="date_end">Date end:</label>
            <input type="date" id="date_end" name="date_end" />
        </div>

        <div class="form-group">
            <label for="price">Price:</label>
            <input type="number" id="price" name="price" />
        </div>

        <input type="hidden" id="editId" name="editId" value="0" />

        <div class="form-group">
            <button type="submit" class="btn btn-success" id="submit" style="text-transform: capitalize;">
                add interval
            </button>
            <button class="btn btn-danger" id="cancel">Cancel</button>
        </div>
    </form>
</div>


<script>
    /**
     * Show intervals table with markup
     */
    function showTable(intervals) {
        let trHTML = '';
        $("#intervals tr").slice(1).remove();

        $.each(intervals, function (i, interval) {
            trHTML += '<tr><td>' +
                '<a href="#"><span data-id="' + interval.id +
                    '" class="glyphicon glyphicon-remove-circle"></span></a>' +
                '</td><td>' +
                '<a href="#"><span data-id="' + interval.id +
                    '" class="glyphicon glyphicon-edit" title="Edit interval"></span></a>' +
                '</td><td>' +
                interval.date_start + '</td><td>' +
                interval.date_end + '</td><td>' +
                interval.price + '</td></tr>';
        });
        $('#intervals').append(trHTML);
    }

    /**
     * Execute POST-ajax request with body cmdPacket,
     * when successful - execute callback
     * @param cmdPacket
     * @param cb
     */
    function execute(cmdPacket, cb) {
        $.ajax({
            url: '/api.php',
            type: 'POST',
            data: cmdPacket,
            dataType: 'json',
            success: function (response) {
                if (!response.result) {
                    alert(response.message);
                    document.location.reload();
                } else {
                    cb(response.data);
                }
            }
        });
    }

    function toggleForm(caption, editId) {
        $(".glyphicon-plus-sign").toggle();
        $(".glyphicon-edit").toggle();
        $("#add_interval_form").toggle();
        $("#editId").val(editId);

        if (caption) {
            $("#submit").text(caption + ' interval');
            $("#caption").text('Lets ' + caption + ' interval.');
        }
    }

    // document AJAX-loaded, intervals cached by js-variable
    // so we prevent sql-query "SELECT * from intervals"
    let intervals = [];

    execute({cmd: 'index'}, (data) => {
        intervals = data;
        showTable(intervals);
    });

    $("#truncate_db").click( () => {
        execute({cmd: 'truncateDb'}, () => {
            intervals = [];
            $("#intervals tr").slice(1).remove();
        });
    });

    $("#intervals").on("click", '.glyphicon-remove-circle[id!="truncate_db"]', function() {
        let id = $(this).data('id');
        execute({cmd: 'removeById', id: id}, () => {
            $(this).parents()[2].remove();
            intervals = intervals.filter((interval) => interval.id != id);
        });
    });

    $("#intervals").on("click", '.glyphicon-edit', function() {
        $(this).parents()[2].style.backgroundColor = "yellow";

        let editId = $(this).data('id'),
            finded = intervals.find((interval) => interval.id == editId);

        $("#date_start").val(finded.date_start);
        $("#date_end").val(finded.date_end);
        $("#price").val(finded.price);
        toggleForm('edit', editId);
    });

    $(".glyphicon-plus-sign").click(() => toggleForm('add', 0));

    $("form").submit((e) => {
        e.preventDefault();

        if (Date.parse($("#date_start").val()) > Date.parse($("#date_end").val())) {
            alert('Sorry, but date_start is later then date_end');
            return false
        }

        let editId = $("#editId").val(),
            params = {
                cmd: (editId === "0") ? 'add' : 'edit',
                new: $(this).serialize(),
                old: () => {
                    let find = intervals.find((interval) => interval.id == editId);
                    return find ? $.param(find) : '';
                },
                intervals: JSON.stringify(intervals)
        };

        execute(params, (data) => {
            intervals = data;
            showTable(intervals);
            toggleForm('add', 0)
        })
    });

    $("#cancel").click(() => {
        $("#intervals tr").each(function () {
            $(this).css('backgroundColor', '#FFF')
        });
        toggleForm('add', 0);
        return false
    });

</script>
</body>
</html>
