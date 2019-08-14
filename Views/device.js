var device = {
    'list':function() {
        var result = {};
        $.ajax({ url: path+"device/list.json", dataType: 'json', async: false, success: function(data) {result = data;} });
        return result;
    },

    'get':function(id) {
        var result = {};
        $.ajax({ url: path+"device/get.json", data: "id="+id, async: false, success: function(data) {result = data;} });
        return result;
    },

    'set':function(id, fields) {
        var result = {};
        $.ajax({ url: path+"device/set.json", data: "id="+id+"&fields="+JSON.stringify(fields), async: false, success: function(data) {result = data;} });
        return result;
    },

    'setNewDeviceKey':function(id) {
        var result = {};
        $.ajax({ url: path+"device/setnewdevicekey.json", data: "id="+id, async: false, success: function(data) {result = data;} });
        return result;
    },

    'remove':function(id) {
        var result = {};
        $.ajax({ url: path+"device/delete.json", data: "id="+id, async: false, success: function(data) {result = data;} });
        return result;
    },

    'create':function(nodeid, name, description, type) {
        var result = {};
        $.ajax({ url: path+"device/create.json", data: "nodeid="+nodeid+"&name="+name+"&description="+description+"&type="+type, async: false, success: function(data) {result = data;} });
        return result;
    },

    'init':function(id, template) {
        var result = {};
        $.ajax({ url: path+"device/init.json?id="+id, type: 'POST', data: "template="+JSON.stringify(template), dataType: 'json', async: false, success: function(data) {result = data;} });
        return result;
    },

    'prepareTemplate':function(id) {
        var result = {};
        $.ajax({ url: path+"device/template/prepare.json", data: "id="+id, dataType: 'json', async: false, success: function(data) {result = data;} });
        return result;
    }
}

/**
 * async version of above functions
 * similar to above but returns the ajax promise for the calling function to act on the response without blocking
 * 
 * eg.
 * device2.remove(device_id).done(function(data){
 *    console.log(data)
 * })
 */
 
var device2 = {
    remove: function(id) {
        return $.getJSON(path+"device/delete.json", "id="+id)
    }
}