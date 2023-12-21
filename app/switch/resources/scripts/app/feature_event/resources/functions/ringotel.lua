local ringotel = {}

function ringotel.post(body, url)
    local url = url or "https://shell.ringotel.co";
    local auth_header = "'Authorization: Bearer "..api_key.."'"
    local command = url .. " append_headers "..auth_header.." Content-Type 'application/json' post ";
    command = command .. body;
    local api = freeswitch.API();
    return api:execute("curl", command);
end

function ringotel.dnd(dnd, id, orgid)
    local json = require "resources.functions.lunajson"
    local body = { method = "setUserState", params = {id = id, orgid = orgid, dnd = dnd}};
    return ringotel_api.post(json.encode(body), "https://shell.ringotel.co");
end

function ringotel.get_user_data(user,domain_name)
    local Database = require "resources.functions.database";
    local dbh = Database.new('system');
    local sql = "select * from v_ringotel_users r ";
    sql = sql .. "join v_domains d on d.domain_uuid = r.domain_uuid "
    sql = sql .. "where d.domain_name = :domain_name ";
    sql = sql .. "and r.extension = :extension ";
    sql = sql .. "and r.activated = true ";
    local params = {domain_name = domain_name, extension = user};
    dbh:query(sql, params, function(row)
        extension_uuid = row.extension_uuid;
        ringotel_id = row.ringotel_id;
        orgid = row.orgid;
        activated = row.activated;
    end)

    return ringotel_id, orgid, activated;
end

return ringotel