<?php // Author: Whole team - see relevant template to see author of each function.

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app->get("/", function() use ($app) {
    $tickets_sql = "SELECT Problem.problem_id, Problem.received , Problem.priority, Problem.subject FROM Problem WHERE status=0";
    $openTickets = $app["db"]->fetchAll($tickets_sql);

    $specialists_sql = "SELECT Operator.operator_id, Operator.first_name, Operator.last_name, (SELECT COUNT(*) FROM Problem WHERE Problem.status=0 AND Problem.operator_id=Operator.operator_id) as open_tickets FROM Operator INNER JOIN Operator_ProblemType ON Operator.operator_id=Operator_ProblemType.operator_id GROUP BY Operator.operator_id ORDER BY open_tickets DESC ";
    $specialists_assigned = $app["db"]->fetchAll($specialists_sql);

    return $app["twig"]->render("home.twig", array(
        "tickets" => $openTickets,
        "specialists" => $specialists_assigned
    ));
});

$app->get("/record-call", function() use ($app) {
    return $app["twig"]->render("record-call.twig");
});

$app->post("record-call/{step}", function($step, Request $request) use ($app) {
    // Each page will post it's data, and the data of any previous pages. Using that it'll generate the next page,
    switch ($step) {
        case 0: // If loading the first page...
            $employees = $app["db"]->fetchAll("SELECT * FROM Employee"); // Get all the employees from the db

            return $app["twig"]->render("call/1-caller.twig", array(
                "employees" => $employees
            ));

        case 1: // If an employee has been selected...
            $hardware = $app["db"]->fetchAll( // Get all hardware from the db
                "SELECT Hardware.hardware_id, Hardware.type, Hardware.make, Hardware.model FROM Hardware"
            );

            $software = $app["db"]->fetchAll( // Get all software from the db
                "SELECT Software.software_id, Software.name FROM Software"
            );

            // Get all the open problems for the selected employee
            $existingProblems = $app["db"]->fetchAll("
                SELECT
                  Problem.problem_id,
                  Problem.subject,
                  Problem.priority,
                  ProblemType.problemtype_id,
                  ProblemType.type,
                  TIMESTAMPDIFF(DAY, Problem.received, NOW()) AS age,
                  GROUP_CONCAT(DISTINCT Problem_Hardware.hardware_item_id) AS hardware_items,
                  GROUP_CONCAT(DISTINCT Problem_Software.software_item_id) AS software_items
                FROM Problem
                  LEFT JOIN ProblemType ON Problem.problem_id = ProblemType.problemtype_id
                  LEFT JOIN Problem_Hardware ON Problem.problem_id = Problem_Hardware.problem_id
                  LEFT JOIN Problem_Software ON Problem.problem_id = Problem_Software.problem_id
                WHERE Problem.employee_id = ? AND Problem.status = 0
                GROUP BY Problem.problem_id;
                ", array((int)$request->get("employee_id"))
            );

            foreach ($existingProblems as $i => $problem ) {
                // If there's hardware items associated with the problem, get them.
                $sql = "
                SELECT
                  HardwareItem.hardwareitem_id,
                  HardwareItem.serial_no,
                  TIMESTAMPDIFF(DAY, HardwareItem.purchased, NOW()) AS age,
                  Hardware.type,
                  Hardware.make,
                  Hardware.model
                FROM HardwareItem
                  INNER JOIN Hardware ON HardwareItem.hardware_id = Hardware.hardware_id
                WHERE HardwareItem.hardwareitem_id IN (" . $problem["hardware_items"] . ");";
                if ($problem["hardware_items"]) {
                    $existingProblems[$i]["hardware"] = $app["db"]->fetchAll($sql);
                }

                // If there's software items associated with the problem, get them.
                $sql = "
                SELECT
                  SoftwareItem.softwareitem_id,
                  SoftwareItem.license_key,
                  SoftwareItem.expires,
                  TIMESTAMPDIFF(DAY, SoftwareItem.expires, NOW()) AS expires_in,
                  Software.name
                FROM SoftwareItem
                  INNER JOIN Software ON SoftwareItem.software_id = Software.software_id
                WHERE SoftwareItem.softwareitem_id IN (" . $problem["software_items"] . ");";
                if ($problem["software_items"]) {
                    $existingProblems[$i]["software"] = $app["db"]->fetchAll($sql);
                }

                // Get all the calls associated with the ticket.
                $sql = "
                SELECT
                  Call.operator_id,
                  Operator.first_name,
                  Operator.last_name,
                  Call.message,
                  TIMESTAMPDIFF(DAY, Call.time, NOW()) AS age,
                  'call' as type
                FROM `Call`
                  INNER JOIN Call_Problem ON `Call`.call_id = Call_Problem.call_id
                  INNER JOIN Operator ON `Call`.operator_id = Operator.operator_id
                WHERE Call_Problem.problem_id = ?;";
                $existingProblems[$i]["calls"] = $app["db"]->fetchAll($sql, array($problem["problem_id"]));

                // Get all the notes associated with the ticket.
                $sql = "
                SELECT
                  ProblemNote.operator_id,
                  Operator.first_name,
                  Operator.last_name,
                  ProblemNote.message,
                  TIMESTAMPDIFF(DAY, ProblemNote.time, NOW()) AS age,
                  'note' as type
                FROM ProblemNote
                  INNER JOIN Operator ON ProblemNote.operator_id = Operator.operator_id
                WHERE ProblemNote.problem_id = ?;";
                $existingProblems[$i]["notes"] = $app["db"]->fetchAll($sql, array($problem["problem_id"]));
            }

            return $app["twig"]->render("call/2-problem.twig", array(
                "tickets" => $existingProblems,
                "hardware" => $hardware,
                "software" => $software
            ));

        case 2: // If the problems have been selected...
            $data = $request->get("problems");
            $existingProblemIDs = array();
            $existingProblemNotes = array();
            $newProblems = array();
            foreach ($data as $i => $problem) {
                if ($problem["type"] == "existing") {
                    $existingProblemIDs[] = (int)$problem["problem_id"]; // Store the existing problems ID
                    $existingProblemNotes[$problem["problem_id"]] = $problem["note"]; // Keep the note, we'll add it later
                } else {
                    $newProblems[] = $problem;
                }
            }

            if (count($existingProblemIDs) > 0) { // If there's existing problems to lookup
                $sql = "
            SELECT
              Problem.problem_id,
              Problem.subject,
              Problem.problemtype_id,
              Problem.operator_id,
              Problem.priority,
              Operator.first_name,
              Operator.last_name,
              'existing' AS type
            FROM Problem
              INNER JOIN Operator ON Problem.operator_id = Operator.operator_id
            WHERE Problem.problem_id IN (" . implode(",", $existingProblemIDs) . ");";
                $existingProblems = $app["db"]->fetchAll($sql); // Get the information on the existing problems the call is concerning.
            } else {
                $existingProblems = array();
            }

            // This query traverses the problem type hierarchy and gets the specialists at each one.
            $specialistsSql = "
            SELECT DISTINCT Operator.operator_id, Operator.first_name, Operator.last_name, ProblemType.problemtype_id, ProblemType.type,
              (SELECT COUNT(*) FROM Problem WHERE Problem.operator_id = Operator.operator_id AND Problem.status = 0) AS open_tickets
            FROM Operator
              INNER JOIN Operator_ProblemType ON Operator.operator_id = Operator_ProblemType.operator_id
              INNER JOIN ProblemType ON Operator_ProblemType.problemtype_id = ProblemType.problemtype_id
              JOIN Problem ON Operator.operator_id = Problem.operator_id
            WHERE ProblemType.problemtype_id IN (
              SELECT
                T2.problemtype_id
              FROM (
                     SELECT
                       @r                           AS _id,
                       (SELECT @r := parent_id
                        FROM ProblemType
                        WHERE problemtype_id = _id) AS parent_id,
                       @l := @l + 1                 AS level
                     FROM
                       (SELECT
                          @r := ?,
                          @l := 0) vars,
                       ProblemType pt
                     WHERE @r IS NOT NULL
                   ) T1
                JOIN ProblemType T2
                  ON T1._id = T2.problemtype_id
              ORDER BY T1.level ASC
            ) ORDER BY open_tickets ASC;";


            // For every problem, get the assignable specialists.
            foreach ($newProblems as $i => $problem) {
                $newProblems[$i]["specialists"] = $app["db"]->fetchAll($specialistsSql, array((int)$problem["problemtype_id"]));
            }
            foreach ($existingProblems as $i => $problem) {
                $existingProblems[$i]["specialists"] = $app["db"]->fetchAll($specialistsSql, array((int)$problem["problemtype_id"]));
                $existingProblems[$i]["note"] = $existingProblemNotes[(string)$problem["problem_id"]]; // Readd the note to the problem.
            }

            return $app["twig"]->render("call/3-specialist.twig", array(
                "existingProblems" => $existingProblems,
                "newProblems" => $newProblems
            ));

        case 3: // If the specialists have been selected...
            $problems = $request->get("problems");
            foreach ($problems as $i => $problem) { // The hardware/software lists goto null if they're empty for some reason, so we make them an empty list again.
                if (!key_exists("hardware", $problem)) $problems[$i]["hardware"] = array();
                if (!key_exists("software", $problem)) $problems[$i]["software"] = array();
            }

            return $app["twig"]->render("call/4-confirm.twig", array(
                "problems" => $problems
            ));

        case 4: // If the operator has confirmed the tickets...
            $problems = $request->get("problems");
            $logged_in_user_id = $app["db"]->fetchColumn("SELECT operator_id FROM Operator WHERE email = ?", array($app['security.token_storage']->getToken()->getUser()), 0);

            foreach ($problems as $i => $problem) {
                if ($problem["type"] == "new") {
                    $app["db"]->insert( // Add the new problems to the db
                        "Problem",
                        array(
                            "employee_id" => $request->get("employee")["employee_id"],
                            "operator_id" => (int)$problem["operator_id"],
                            "problemtype_id" => (int)$problem["problemtype_id"],
                            "subject" => $problem["subject"],
                            "priority" => (int)$problem["priority"],
                            "status" => 0,
                            "received" => new \DateTime(),
                            "last_updated" => new \DateTime()
                        ),
                        array(
                            PDO::PARAM_INT,
                            PDO::PARAM_INT,
                            PDO::PARAM_INT,
                            PDO::PARAM_STR,
                            PDO::PARAM_INT,
                            PDO::PARAM_INT,
                            "datetime",
                            "datetime"
                        )
                    );

                    $problemID = $app["db"]->lastInsertId();

                    foreach ($problem["hardware"] as $j => $item) { // Link any hardware to the problem
                        $hw_item_id = $app["db"]->fetchColumn("SELECT hardwareitem_id FROM HardwareItem WHERE serial_no = ? ", array($item[1]), 0);
                        $app["db"]->insert("Problem_Hardware", array("problem_id" => $problemID, "hardware_item_id" => (int)$hw_item_id));
                    }

                    foreach ($problem["software"] as $j => $item) { // Link any software to the problem
                        $sw_item_id = $app["db"]->fetchColumn("SELECT softwareitem_id FROM SoftwareItem WHERE license_key = ? ", array($item[1]), 0);
                        $app["db"]->insert("Problem_Software", array("problem_id" => $problemID, "software_item_id" => (int)$sw_item_id));
                    }

                } else {
                    $app["db"]->update( // Update the existing problem in the db
                        "Problem",
                        array("operator_id" => (int)$problem["operator_id"], "last_updated" => new \DateTime()),
                        array("problem_id" => (int)$problem["problem_id"]),
                        array(PDO::PARAM_INT, "datetime")
                    );

                    $problemID = (int)$problem["problem_id"];
                }

                $app["db"]->insert( // Add the call note to the db
                    "`Call`",
                    array(
                        "caller_id" => (int)$request->get("employee")["employee_id"],
                        "operator_id" => (int)$logged_in_user_id,
                        "message" => $problem["note"],
                        "time" => new \DateTime()
                    ),
                    array(
                        PDO::PARAM_INT,
                        PDO::PARAM_INT,
                        PDO::PARAM_STR,
                        "datetime"
                    )
                );
                $app["db"]->insert("Call_Problem", // Link the call to the problem
                    array(
                        "call_id" => $app["db"]->lastInsertId(),
                        "problem_id" => $problemID
                    ));
            }

            return $app["twig"]->render("call/5-success.twig");
    }
})->assert("step", "[0-4]");

$app->get("/tickets", function() use ($app) {
    $sql = "
            SELECT 
               problem_id,
               subject,
               priority,
               status,
               received,
               operator_id
            FROM Problem 
            ORDER BY problem_id";
    $user_id = $app["db"]->fetchAll("SELECT operator_id FROM Operator WHERE email = ?", array($app['security.token_storage']->getToken()->getUser()))[0];
    $tickets = $app['db']->fetchAll($sql);
    return $app["twig"]->render("tickets.twig", array(
        "user" => $user_id,
        "tickets" => $tickets
    ));
});

$app->get("/tickets/{id}", function($id) use ($app) {
    // This is called via AJAX and returns the ticket info row that then expands, as well as the 2 modals included in the row.
    $sqlInfo = "
                SELECT 
                  Problem.subject,
                  Problem.priority,
                  Problem.status,
                  Operator.first_name AS OpFirst,
                  Operator.last_name AS OpLast, 
                  Operator.operator_id,
                  ProblemType.type, 
                  ProblemType.problemtype_id,
                  Employee.first_name AS EmpFirst, 
                  Employee.last_name AS EmpLast,
                  Problem.last_updated 
                FROM Problem 
                  INNER JOIN Employee ON Problem.employee_id = Employee.employee_id 
                  INNER JOIN ProblemType ON Problem.problemtype_id = ProblemType.problemtype_id
                  INNER JOIN Operator ON Problem.operator_id = Operator.operator_id 
                WHERE Problem.problem_id = $id";
    $sqlHardware = "
                SELECT
                  HardwareItem.hardwareitem_id,
                  HardwareItem.serial_no,
                  HardwareItem.purchased,
                  Hardware.type,
                  Hardware.make,
                  Hardware.model
                FROM Problem_Hardware
                  INNER JOIN HardwareItem ON Problem_Hardware.hardware_item_id = HardwareItem.hardwareitem_id
                  INNER JOIN Hardware ON HardwareItem.hardware_id = Hardware.hardware_id
                WHERE Problem_Hardware.problem_id = $id";
    $sqlSoftware = "
                SELECT
                  SoftwareItem.softwareitem_id,
                  SoftwareItem.license_key,
                  SoftwareItem.expires,
                  SoftwareItem.expires,
                  Software.name
                FROM Problem_Software
                  INNER JOIN SoftwareItem ON Problem_Software.software_item_id = SoftwareItem.softwareitem_id
                  INNER JOIN Software ON SoftwareItem.software_id = Software.software_id
                WHERE Problem_Software.problem_id = $id";
    $sqlCall = "
                SELECT
                  Call.operator_id,
                  Operator.first_name,
                  Operator.last_name,
                  Call.message,
                  Call.time,
                  'call' as type
                FROM `Call`
                  INNER JOIN Call_Problem ON `Call`.call_id = Call_Problem.call_id
                  INNER JOIN Operator ON `Call`.operator_id = Operator.operator_id
                WHERE Call_Problem.problem_id = $id";
    $sqlNote = "
                SELECT
                  ProblemNote.operator_id,
                  Operator.first_name,
                  Operator.last_name,
                  ProblemNote.message,
                  ProblemNote.time,
                  'note' as type
                FROM ProblemNote
                  INNER JOIN Operator ON ProblemNote.operator_id = Operator.operator_id
                WHERE ProblemNote.problem_id = $id";
    $sqlRelatedOperators ="
                SELECT
                  first_name,
                  Operator.operator_id,
                  ProblemType.type,
                  Operator_ProblemType.problemtype_id
                FROM
                  Operator
                INNER JOIN
                  Operator_ProblemType ON Operator.operator_id = Operator_ProblemType.operator_id
                INNER JOIN
                  ProblemType ON Operator_ProblemType.problemtype_id = ProblemType.problemtype_id";
    $ticketInfo = $app['db']->fetchAll($sqlInfo);
    $ticketMessages = $app['db']->fetchAll($sqlNote . " UNION " . $sqlCall . " ORDER BY time DESC");
    $ticketHardware = $app['db']->fetchAll($sqlHardware);
    $ticketSoftware = $app['db']->fetchAll($sqlSoftware);
    $relatedOperators = $app['db']->fetchAll($sqlRelatedOperators);
    $allProblemTypes = $app['db']->fetchAll("SELECT type, problemtype_id FROM ProblemType");
    return $app["twig"]->render("ticket-info.twig", array(
        "ticketInfo" => $ticketInfo,
        "ticketMessage" => $ticketMessages,
        "ticketHardware" => $ticketHardware,
        "ticketSoftware" => $ticketSoftware,
        "relatedOperators" => $relatedOperators,
        "problemTypes" => $allProblemTypes,
        "id" => $id
    ));
})->assert("id", "\d+");

$app->post("/tickets/update", function(Request $request) use ($app) {
    $sql = '
            UPDATE Problem 
            SET subject = ?, priority = ?, status = ?, operator_id = ?, problemtype_id = ? 
            WHERE problem_id = ?';
    $app["db"]->executeUpdate($sql, array($request->get('subject'),$request->get('priority'),$request->get('status'),$request->get('operator_id'),$request->get('problemtype_id'),$request->get('problem_id')));
    return new Response("", Response::HTTP_OK);
});
$app->post("/tickets/insertnote", function(Request $request) use ($app) {
    $app["db"]->insert("ProblemNote", array("problemnote_id" => NULL, "problem_id" => $request->get('problem_id'), "operator_id" => $request->get('operator_id'), "message" => $request->get('message'), "time" => date('Y-m-d H:i:s')));
    $app["db"]->executeUpdate('UPDATE Problem SET last_updated = "' . date('Y-m-d H:i:s') . '" WHERE problem_id = ' . $request->get('problem_id'));
    return new Response("", Response::HTTP_OK);
});
$app->post("/tickets/probharddel", function(Request $request) use ($app) {
    $app["db"]->delete("Problem_Hardware", array("problem_id"=>$request->get('problem_id'), "hardware_item_id"=>$request->get('hardware_item_id')));
    return new Response("", Response::HTTP_OK);
});
$app->post("/tickets/probsoftdel", function(Request $request) use ($app) {
    $app["db"]->delete("Problem_Software", array("problem_id"=>$request->get('problem_id'), "software_item_id"=>$request->get('software_item_id')));
    return new Response("", Response::HTTP_OK);
});
$app->get("/specialists", function() use ($app) {
    //Returns details for each specialist for the main specialists list incl. no. of open tickets
    $sql = "SELECT O.operator_id, O.first_name, O.last_name, (
                SELECT COUNT(*)
                FROM Problem P
                WHERE P.status = 0
                AND P.operator_id = O.operator_id
              ) AS open_tickets,
              GROUP_CONCAT(P.type SEPARATOR ',') AS specialisms
            FROM Operator O
            INNER JOIN Operator_ProblemType OPT ON O.operator_id = OPT.operator_id
            INNER JOIN ProblemType P ON OPT.problemtype_id = P.problemtype_id
            GROUP BY O.operator_id;";

    $specialists = $app["db"]->fetchAll($sql);

    return $app["twig"]->render("specialists.twig", array(
        "specialists" => $specialists
    ));
});

$app->get("/specialists/{id}", function($id) use ($app) {
    //Used for dropdown expansion row
    //Returns each ticket and related ticket info incl. age for each open ticket of relative specialist
    $sql = "SELECT *,
              TIMESTAMPDIFF(day, Problem.received, CURRENT_TIMESTAMP) AS age
            FROM Problem
            WHERE Problem.Operator_id=?
            AND Problem.status=0;";

    $specialistTickets = $app["db"]->fetchAll($sql, array($id));

    return $app["twig"]->render("specialist-info.twig", array(
        "specialistTickets" => $specialistTickets,
        "id" => $id
    ));
})->assert("id", "\d+");

$app->get("/specialists/{id}/specialisms", function($id) use ($app) {
    //returns name and specialisms for Edit Specialism Modal for chosen specialist
    $sql = "SELECT GROUP_CONCAT(P.type SEPARATOR ',') AS specialisms, O.first_name, O.last_name
            FROM Operator O
            INNER JOIN Operator_ProblemType OPT ON O.operator_id = OPT.operator_id
            INNER JOIN ProblemType P ON OPT.problemtype_id = P.problemtype_id       
            WHERE O.operator_id=?
            GROUP BY O.operator_id;";
    $specialist = $app["db"]->fetchAll($sql, array($id))[0];

    //returns list of specialisms that the chosen specialist is NOT assigned to
    $sql = "SELECT DISTINCT ProblemType.type,ProblemType.problemtype_id
            FROM ProblemType
            INNER JOIN Operator_ProblemType ON Operator_ProblemType.problemtype_id = ProblemType.problemtype_id
            WHERE ProblemType.problemtype_id NOT IN (
            SELECT Operator_ProblemType.problemtype_id
            FROM Operator_ProblemType
            WHERE Operator_ProblemType.operator_id = ?);";
    $specialismsAvailable = $app["db"]->fetchAll($sql, array($id));

    return $app["twig"]->render("specialisms-edit.twig", array(
        "specialist" => $specialist,
        "specialismsAvailable" => $specialismsAvailable,
        "id"=>$id
    ));
})->assert("id", "\d+");

$app->post("/specialists/{id}/addSpecialism", function($id, Request $request ) use ($app) {
    //add chosen new specialism to database for chosen specialist
    $app["db"]->insert(Operator_ProblemType, array(operator_id=>$id, problemtype_id=>$request->get("problemtype_id")));

    return "success";
});

$app->get("/analytics", function () use ($app) {
    // All the graphs take a JSON input, so we can pass Twig an array(), then use {{ data|json_encode() }} to convert it.

    // work out number of currently open tickets
    $sql = "SELECT COUNT(Problem.status) AS open_tickets
                FROM Problem
                WHERE Problem.status=0;";
    $openTickets = $app["db"]->fetchAll($sql);

    // work out average time taken to solve a ticket
    $sql = "SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, Problem.received, Problem.last_updated))) AS average_time
                FROM Problem
                WHERE  Problem.status=1;";
    $averageTime = $app["db"]->fetchAll($sql);

    // count the top 5 most occurring problem types
    $sql = "SELECT ProblemType.type, COUNT(Problem.problemtype_id) AS problem_types
                FROM Problem INNER JOIN ProblemType ON Problem.problemtype_id = ProblemType.problemtype_id
                GROUP BY Problem.problemtype_id
                ORDER BY problem_types DESC
                LIMIT 5;";
    $top5ProblemType = $app["db"]->fetchAll($sql);

    // convert each number string to an int
    foreach ($top5ProblemType as $i => $problem) {
        $top5ProblemType[$i] = array_values($problem);
        $top5ProblemType[$i][1] = intval($top5ProblemType[$i][1]);
    };

    array_unshift($top5ProblemType, array('Problem Type', 'Number of Tickets')); // add headings to the array

    // find the number of problems solved by each specialist
    $sql = "SELECT CONCAT(Operator.first_name, ' ', Operator.last_name) AS name, COUNT(Problem.status) AS solved_tickets
                FROM Problem INNER JOIN Operator ON Problem.operator_id = Operator.operator_id
                WHERE Problem.status=1
                GROUP BY Problem.operator_id
                ORDER BY solved_tickets DESC;";
    $solvedByEachSpecialist = $app["db"]->fetchAll($sql);

    // convert each number string to an int
    foreach ($solvedByEachSpecialist as $i => $solved) {
        $solvedByEachSpecialist[$i] = array_values($solved);
        $solvedByEachSpecialist[$i][1] = intval($solvedByEachSpecialist[$i][1]);
    }

    array_unshift($solvedByEachSpecialist, array('Name', 'Number of Solved Tickets')); // add headings to the array

    // find the number of problems solved each day
    $sql = "SELECT DATE(Problem.last_updated), COUNT(DATE(Problem.last_updated))
                FROM Problem
                WHERE Problem.status = 1
                GROUP BY DATE(Problem.last_updated)";
    $solvedEachDay = $app["db"]->fetchAll($sql);

    // convert each number string to an int
    foreach ($solvedEachDay as $i => $solved) {
        $solvedEachDay[$i] = array_values($solved);
        $solvedEachDay[$i][1] = intval($solvedEachDay[$i][1]);
    }

    array_unshift($solvedEachDay, array('Date', 'Number of Solved Tickets')); // add headings to the array

    // find the number of tickets currently assigned to each specialist
    $sql = "SELECT CONCAT(Operator.first_name, ' ', Operator.last_name) AS name, COUNT(Problem.status) AS open_tickets
                FROM Problem INNER JOIN Operator ON Problem.operator_id = Operator.operator_id
                WHERE Problem.status=0
                GROUP BY Problem.operator_id
                ORDER BY open_tickets DESC;";
    $assignedToEachSpecialist = $app["db"]->fetchAll($sql);

    // convert each number string to an int
    foreach ($assignedToEachSpecialist as $i => $assigned) {
        $assignedToEachSpecialist[$i] = array_values($assigned);
        $assignedToEachSpecialist[$i][1] = intval($assignedToEachSpecialist[$i][1]);
    }

    array_unshift($assignedToEachSpecialist, array('Name', 'Number of Assigned Tickets')); // add headings to the array

    return $app["twig"]->render("analytics.twig", array(
        "openTickets" => $openTickets,
        "averageTime" => $averageTime,
        "top5ProblemType" => $top5ProblemType,
        "solvedByEachSpecialist" => $solvedByEachSpecialist,
        "solvedEachDay" => $solvedEachDay,
        "assignedToEachSpecialist" => $assignedToEachSpecialist
    ));
});

$app->get("/softhardware", function () use ($app) {
    //get all the information for each piece of software and hardware in the database, and also count the amount of individual items that each piece of Software or Hardware has
    $software = $app["db"]->fetchAll("SELECT Software.*, COUNT(SoftwareItem.softwareitem_id) AS quantity FROM SoftwareItem RIGHT JOIN Software ON SoftwareItem.software_id = Software.software_id GROUP BY Software.software_id");
    $hardware = $app["db"]->fetchAll("SELECT Hardware.*, COUNT(HardwareItem.hardwareitem_id) AS quantity FROM HardwareItem RIGHT JOIN Hardware ON HardwareItem.hardware_id = Hardware.hardware_id GROUP BY Hardware.hardware_id");

    //get all the information for every individual item from the database
    $softwareItems = $app["db"]->fetchAll("SELECT * FROM SoftwareItem");
    $hardwareItems = $app["db"]->fetchAll("SELECT * FROM HardwareItem");

    return $app["twig"]->render("softhardware/softhardware.twig", array(
        "software" => $software,
        "hardware" => $hardware,

        "softwareItem" => $softwareItems,
        "hardwareItem" => $hardwareItems
    ));
});

$app->get("/softhardware/{type}/{change}/{id}", function ($type, $change, $id) use ($app) {
    //CALLED BY FUNCTION LOADFORM() IN SOFTHARDWARE.TWIG

    //called to open the input form (HTML code stored in hardware-form.twig or software-form.twig) that allows the user to try and insert a new piece, or update a current of Software or Hardware into the database
    if ($type == "soft") {
        if ($id == 0) {
            //if the value of $id is zero, it means this function has been called to display an empty input form, for a new piece of software
            $formPlaceholders = [name => ""];
        } else {
            //if not zero, fetch the name of the specific piece of Software that is being updated, so that it can be displayed already in the input form
            $formPlaceholders = $app["db"]->fetchAll("SELECT name FROM Software WHERE software_id=?", array($id))[0];
        }
        //load the form with the appropriate placeholder (determined above)
        return $app["twig"]->render("softhardware/software-form.twig", array("formType" => $change, "placeholder" => $formPlaceholders));
    } else if ($type == "hard") {
        if ($id == 0) {
            //again, if zero, its an empty page so the placeholders are defaulted
            $formPlaceholders = [make => "", model => "", deviceType => "Choose here"];
        } else {
            //fetch the name (make + model) and device type of the specific piece of Hardware that is being updated so that it can be displayed already in the input form
            $formPlaceholders = $app["db"]->fetchAll("SELECT make, model, type AS deviceType FROM Hardware WHERE hardware_id=?", array($id))[0];
        }
        //load the form with the appropriate information for the palceholders that is determined above.
        return $app["twig"]->render("softhardware/hardware-form.twig", array("formType" => $change, "placeholder" => $formPlaceholders));
    }
})->assert("type", "^(soft|hard)$")
    ->assert("change", "^(New|Edit)$")
    ->assert("id", "\d+");
//validates the variables/url that are passed into this function

$app->get("/softhardware/{type}/{id}", function ($type, $id) use ($app) {
    //CALLED BY FUNCTION LOADKEYMODAL() IN SOFTHARDWARE.TWIG

    //called to open the modal that will display all the Licenses/Serials of the individual items of the specific type of Software or Hardware that is passed into this function
    if ($type == "soft") {
        //if a piece of software is clicked, fetch all the information about items of the piece of software with the id that is passed into this function (the piece of software clicked that calls the function in softhardware.twig that then calls this functiom)
        $listVals = $app["db"]->fetchAll("SELECT softwareitem_id AS itemId, license_key AS uniqueCode, expires AS date FROM SoftwareItem WHERE software_id=?", array($id));
        $parentInfo = $app["db"]->fetchAll("SELECT name, software_id AS wareId FROM Software WHERE software_id=?", array($id))[0];
    } else {
        //if a piece of software is clicked, fetch all the information about items of the piece of software with the id that is passed into this function
        $listVals = $app["db"]->fetchAll("SELECT hardwareitem_id AS itemId, serial_no AS uniqueCode, purchased AS date FROM HardwareItem WHERE hardware_id=?", array($id));
        $parentInfo = $app["db"]->fetchAll("SELECT CONCAT (Make, ' ', Model) AS name, hardware_id AS wareId FROM Hardware WHERE hardware_id=?", array($id))[0];
    }

    return $app["twig"]->render("softhardware/softhardware-modal.twig", array(
        "info" => $listVals,
        "parent" => $parentInfo,
        "type" => $type
    ));
})->assert("type", "^(soft|hard)$")
    ->assert("id", "\d+");
//validates the variables/url that are passed into this function

$app->post("/softhardware", function (Request $request) use ($app) {
    //CALLED BY FUNCTIONS INPUTWARE() AND ADDNEWITEM() IN SOFTHARDWARE.TWIG

    //this function will insert a new, or Update an existing record into the database, based on the information passed into this function by the $.post array
    if ($request->get('reference') == 'NewSoft') { //adds a new piece of Software
        $stmt = $app["db"]->insert("Software", array("name" => $request->get('name')));
    } else if ($request->get('reference') == 'NewHard') { //adds a new piece of Hardware
        $stmt = $app["db"]->insert("Hardware", array("type" => $request->get('deviceType'), "make" => $request->get('make'), "model" => $request->get('model')));
    } else if ($request->get('reference') == 'softwareNewItem') { //adds a new individual Software Item
        $stmt = $app["db"]->insert("SoftwareItem", array("software_id" => $request->get('softwareRef'), "license_key" => $request->get('license'), "expires" => $request->get('expiry')));
        return "success";
    } else if ($request->get('reference') == 'hardwareNewItem') { //adds a new individual Hardware Item
        $stmt = $app["db"]->insert("HardwareItem", array("hardware_id" => $request->get('hardwareRef'), "serial_no" => $request->get('serial'), "purchased" => $request->get('purchased')));
        return "success";
    } else if ($request->get('reference') == 'EditSoft') { //Updates/Edits a piece of Software already in the database
        $stmt = $app["db"]->executeUpdate("UPDATE Software SET name = ? WHERE software_id = ?", array($request->get('name'), $request->get('id')));
    } else if ($request->get('reference') == 'EditHard') { //Updates/Edits a piece of Hardware already in the database
        $stmt = $app["db"]->executeUpdate("UPDATE Hardware SET make = ?, model=?, type=? WHERE hardware_id = ?", array($request->get('make'), $request->get('model'), $request->get('deviceType'), $request->get('id')));
    }

    return new Response("", Response::HTTP_OK);
});

$app->delete("/softhardware", function (Request $request) use ($app) {
    //CALLED BY FUNCTION DELETEENTRY() IN SOFTHARDWARE.TWIG

    //attempts to delete an individual item from the database (item is determined by the data passed into this function)
    try {
        if ($request->get('reference') == 'soft') {
            $stmt = $app["db"]->delete("SoftwareItem", array("softwareitem_id" => $request->get('itemRef')));
        } else if ($request->get('reference') == 'hard') {
            $stmt = $app["db"]->delete("HardwareItem", array("hardwareitem_id" => $request->get('itemRef')));
        }
        //if on of the above statements executed without failure, return false so that the error message is not displayed
        return false;
    } catch (\Doctrine\DBAL\DBALException $e) {
        //if the item is being referenced in a problem, return the value true so that an error message can be displayed to the user
        return true;
    }
});

$app->get("/problemtypes", function () use ($app) {
    //get all the information about every problem type, and for each problem type, get the amount of children that it has
    $problemTypes = $app["db"]->fetchAll("SELECT parent.*, COUNT(child.parent_id) AS children FROM ProblemType parent LEFT JOIN ProblemType child ON parent.problemtype_id = child.parent_id GROUP BY parent.problemtype_id");

    //load the problemtypes.twig file, passing the information gathered from the sql query into it
    return $app["twig"]->render("problemtypes.twig", array(
        "probTypes" => $problemTypes
    ));
});

$app->get("/problemtypes/{id}", function ($id) use ($app) {
    //CALLED BY FUNCTION LOADCHILDREN() FUNCTION IN PROBLEMTYPES.TWIG

    //get all the information about every child problem type of a parent problem type (passed by its id, $id), and for each children problem type, get the amount of children that it has
    $childrenProbTypes = $app["db"]->fetchAll("SELECT parent.*, COUNT(child.parent_id) AS children FROM ProblemType parent LEFT JOIN ProblemType child ON parent.problemtype_id = child.parent_id WHERE parent.parent_id = ? GROUP BY parent.problemtype_id", array($id));

    //get the name of the parent problem type that has been passed into this function by itsid, $id
    $parentProbType = $app["db"]->fetchAll("SELECT type AS name FROM ProblemType WHERE problemtype_id = ?", array($id))[0];

    //load the problemtypes-child.twig file, which populates the secondary, child problem types table card on the problemtypes page, passing the information gathered from the above sql queries into it
    return $app["twig"]->render("problemtypes-child.twig", array(
        "children" => $childrenProbTypes,
        "parent" => $parentProbType
    ));
})->assert("id", "\d+");

$app->post("/problemtypes", function (Request $request) use ($app) {
    //CALLED BY FUNCTION ADDPROBLEMTYPE() IN PROBLEMTYPES.TWIG

    //insert a new problem type (and relevant information), gathered from the $.post array, into the database
    $stmt = $app["db"]->insert("ProblemType", array("parent_id" => $request->get('parent'), "type" => $request->get('name')));
    return "success";
});

function recursiveProblemFetch($parent_id, $app) {
    if ($parent_id == -1) {
        $sql = "SELECT ProblemType.problemtype_id, ProblemType.parent_id, ProblemType.type
            FROM ProblemType
            WHERE ProblemType.parent_id IS NULL;";
        $results = $app["db"]->fetchAll($sql);
    } else {
        $sql = "SELECT ProblemType.problemtype_id, ProblemType.parent_id, ProblemType.type
            FROM ProblemType
            WHERE ProblemType.parent_id = ?;";
        $results = $app["db"]->fetchAll($sql, array($parent_id));
    }

    $output = array();
    foreach ($results as $i => $result) {
        $output[] = array(
            "text" => $result["type"],
            "problemtype_id" => $result["problemtype_id"],
            "nodes" => recursiveProblemFetch($result["problemtype_id"], $app)
        );
    }
    return $output;
}

$app->get("/api/problemtypes", function() use ($app) {
    // TODO: Return JSON representation of the problem types for using with the tree library.
    return $app->json(recursiveProblemFetch(-1, $app));
});

$app->get("/login", function(Request $request) use ($app) {
    return $app["twig"]->render("login.twig", array(
        "error" => $app["security.last_error"]($request),
        "last_email" => $app["session"]->get("_security.last_username")
    ));
});

$app->get("/api/getEmployee", function(Request $request) use ($app) {
    // TODO: Figure out how to pass ID, or multiple options or something - what if 2 people have same name?
    $stmt = $app["db"]->executeQuery("SELECT * FROM Employee WHERE Name = ?", array($request->query->get("name")));
    $employee = $stmt->fetch();
    return $app->json($employee);
});
